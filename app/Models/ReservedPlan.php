<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReservedPlan extends Model
{
    protected $table = 'v2_reserved_plans';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }
}
