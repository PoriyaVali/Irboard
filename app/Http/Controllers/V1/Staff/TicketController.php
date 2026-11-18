<?php

namespace App\Http\Controllers\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Http\Request;

/**
 * A staff member (a reseller) works with the tickets of its own customers,
 * the users it invited - never another reseller's or the panel's.
 */
class TicketController extends Controller
{
    private function mine(Request $request)
    {
        return Ticket::whereIn('user_id', User::where('invite_user_id', $request->user['id'])->select('id'));
    }

    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $ticket = $this->mine($request)->where('id', $request->input('id'))
                ->first();
            if (!$ticket) {
                abort(500, 'تیکت یافت نشد');
            }
            $ticket['message'] = TicketMessage::where('ticket_id', $ticket->id)->get();
            for ($i = 0; $i < count($ticket['message']); $i++) {
                if ($ticket['message'][$i]['user_id'] !== $ticket->user_id) {
                    $ticket['message'][$i]['is_me'] = true;
                } else {
                    $ticket['message'][$i]['is_me'] = false;
                }
            }
            return response([
                'data' => $ticket
            ]);
        }
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('pageSize') >= 10 ? $request->input('pageSize') : 10;
        $model = $this->mine($request)->orderBy('created_at', 'DESC');
        if ($request->input('status') !== NULL) {
            $model->where('status', $request->input('status'));
        }
        $total = $model->count();
        $res = $model->forPage($current, $pageSize)
            ->get();
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }

    public function reply(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, 'پارامتر نادرست است');
        }
        if (empty($request->input('message'))) {
            abort(500, 'پیام نمی‌تواند خالی باشد');
        }
        if (!$this->mine($request)->where('id', $request->input('id'))->exists()) {
            abort(500, 'تیکت یافت نشد');
        }
        $ticketService = new TicketService();
        $ticketService->replyByAdmin(
            $request->input('id'),
            $request->input('message'),
            $request->user['id']
        );
        return response([
            'data' => true
        ]);
    }

    public function close(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, 'پارامتر نادرست است');
        }
        $ticket = $this->mine($request)->where('id', $request->input('id'))
            ->first();
        if (!$ticket) {
            abort(500, 'تیکت یافت نشد');
        }
        $ticket->status = 1;
        if (!$ticket->save()) {
            abort(500, 'بستن ناموفق بود');
        }
        return response([
            'data' => true
        ]);
    }
}
