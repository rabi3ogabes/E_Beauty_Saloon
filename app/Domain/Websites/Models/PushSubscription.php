<?php

namespace App\Domain\Websites\Models;

use Illuminate\Database\Eloquent\Model;

/** Web push subscription. tenant_id is filled explicitly (central visitors have none). */
class PushSubscription extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['auth_token', 'public_key'];
}
