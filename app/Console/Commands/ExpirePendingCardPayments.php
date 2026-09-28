<?php

namespace App\Console\Commands;

use App\Models\CardPayment;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpirePendingCardPayments extends Command
{
    protected $signature = 'card:expire-pending {--debug}';
    protected $description = 'Expire pending card-to-card payments past their deadline';

    public function handle()
    {
        $debug = $this->option('debug');
        $now = time();

        $rows = CardPayment::where('status', CardPayment::STATUS_PENDING)
            ->where('expires_at', '<', $now)
            ->limit(2000)
            ->get();

        $expired = 0; $fixedPaid = 0; $cancelledOrders = 0;

        foreach ($rows as $c) {
            $order = Order::find($c->order_id);

            // سفارش پرداخت‌شده → رکورد کارت رو تأییدشده کن (نه منقضی)
            if ($order && $order->status == 3) {
                $c->status = CardPayment::STATUS_VERIFIED_FULL;
                $c->updated_at = $now;
                $c->save();
                $fixedPaid++;
                continue;
            }

            // در غیر این صورت منقضی کن
            $c->status = CardPayment::STATUS_EXPIRED;
            $c->updated_at = $now;
            $c->save();
            $expired++;

            // اگه سفارش هنوز بازه، کنسلش کن
            // Through OrderService, which gives back the wallet part of the
            // order; the bare status write here kept it.
            if ($order && $order->status == 0 && (new OrderService($order))->cancel()) {
                $cancelledOrders++;
            }
        }

        $msg = "card:expire-pending → expired={$expired}, cancelledOrders={$cancelledOrders}, fixedPaid={$fixedPaid}, total=" . $rows->count();
        if ($debug) $this->info($msg);
        Log::channel('payment')->info($msg);

        return 0;
    }
}
