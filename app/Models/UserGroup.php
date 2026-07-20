<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An extra access group an admin granted a user, on top of the one they get
 * from their plan (v2_user.group_id).
 *
 * The plan-derived group stays exactly where it always was, so orders,
 * statistics and every other consumer of v2_user.group_id keep working
 * untouched. Rows here only ever ADD reach; deleting them all restores the
 * original single-group behaviour.
 */
class UserGroup extends Model
{
    protected $table = 'v2_user_group';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
