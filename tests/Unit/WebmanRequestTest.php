<?php

namespace Tests\Unit;

use App\Http\WebmanRequest;
use PHPUnit\Framework\TestCase;

class WebmanRequestTest extends TestCase
{
    private $savedGlobals;
    private $hadRawContent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hadRawContent = array_key_exists('HTTP_RAW_REQUEST_DATA', $GLOBALS);
        $this->savedGlobals = [$_GET, $_POST, $_COOKIE, $_FILES, $_SERVER, $GLOBALS['HTTP_RAW_REQUEST_DATA'] ?? null];
        $_GET = $_POST = $_COOKIE = $_FILES = [];
        $_SERVER = ['REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json','REQUEST_URI'=>'/api/v1/server/UniProxy/push','HTTP_HOST'=>'localhost'];
        $GLOBALS['HTTP_RAW_REQUEST_DATA'] = '';
    }

    protected function tearDown(): void
    {
        [$_GET, $_POST, $_COOKIE, $_FILES, $_SERVER, $raw] = $this->savedGlobals;
        if ($this->hadRawContent) $GLOBALS['HTTP_RAW_REQUEST_DATA'] = $raw;
        else unset($GLOBALS['HTTP_RAW_REQUEST_DATA']);
        parent::tearDown();
    }

    public function testRawTrafficBodyAndJsonTypesSurviveTheWorkerBridge(): void
    {
        $_GET = ['node_type'=>'socks5','node_id'=>'1','token'=>'fictional'];
        $_POST = ['17'=>[109,307]];
        foreach (['{"17":[109,307]}','{"17":[1.5,307]}','{"17":["109",307]}','{"17":[-1,307]}'] as $body) {
            $GLOBALS['HTTP_RAW_REQUEST_DATA'] = $body;
            $request = WebmanRequest::capture();
            $this->assertSame($body, $request->getContent());
            $this->assertSame(json_decode($body, true), $request->json()->all());
            $this->assertSame('socks5', $request->query('node_type'));
        }
        // A later cached GET has no body and must not reuse prior request data.
        $_GET = $_POST = []; $GLOBALS['HTTP_RAW_REQUEST_DATA'] = '';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $next = WebmanRequest::capture();
        $this->assertSame('', $next->getContent());
        $this->assertSame([], $next->all());
    }

    public function testMalformedRawJsonIsNotReplacedByTheParsedPostBag(): void
    {
        $_POST = ['17'=>[109,307]];
        $GLOBALS['HTTP_RAW_REQUEST_DATA'] = '{"17":[109,';
        $request = WebmanRequest::capture();
        $this->assertSame('{"17":[109,', $request->getContent());
        $this->expectException(\JsonException::class);
        json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testHeadersCookiesAndFormMethodOverrideSurviveCapture(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_SERVER['HTTP_AUTHORIZATION'] = 'fictional-user-token';
        $_COOKIE = ['fixture'=>'cookie'];
        $_POST = ['_method'=>'PATCH','name'=>'node'];
        $GLOBALS['HTTP_RAW_REQUEST_DATA'] = '_method=PATCH&name=node';
        $request = WebmanRequest::capture();
        $this->assertSame('PATCH', $request->getMethod());
        $this->assertSame('fictional-user-token', $request->header('Authorization'));
        $this->assertSame('cookie', $request->cookie('fixture'));
        $this->assertSame('node', $request->input('name'));
    }

    public function testFormEncodedNonPostMethodsUseTheirOwnRawBody(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded; charset=UTF-8';
        foreach (['PUT','PATCH','DELETE'] as $method) {
            $_SERVER['REQUEST_METHOD'] = $method;
            $_POST = []; // Adapterman stores these values in $_REQUEST instead.
            $GLOBALS['HTTP_RAW_REQUEST_DATA'] = 'name=edited+node&enabled=0';
            $request = WebmanRequest::capture();
            $this->assertSame('edited node', $request->input('name'), $method);
            $this->assertSame('0', $request->input('enabled'), $method);
        }
    }
}
