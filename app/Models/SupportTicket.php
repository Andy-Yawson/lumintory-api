<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'subject',
        'description',
        'priority',
        'status',
        'category',
        'assigned_to',
        'last_reply_at',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
    public function messages()
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id');
    }


}
