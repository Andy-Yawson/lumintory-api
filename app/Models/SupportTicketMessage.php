<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportTicketMessage extends Model
{
    use HasFactory;

    protected $fillable = ['ticket_id', 'user_id', 'message', 'is_internal'];

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
