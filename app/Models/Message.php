<?php

namespace App\Models;

use App\Enums\HxCode;
use App\Enums\MessageFormat;
use App\Enums\MessagePrecedence;
use App\Enums\MessageRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_configuration_id',
        'user_id',
        'format',
        'role',
        'is_sm_message',
        'message_number',
        'precedence',
        'hx_code',
        'station_of_origin',
        'check',
        'place_of_origin',
        'filed_at',
        'addressee_name',
        'addressee_address',
        'addressee_city',
        'addressee_state',
        'addressee_zip',
        'addressee_phone',
        'message_text',
        'signature',
        'sent_to',
        'received_from',
        'notes',
        'ics_to_position',
        'ics_from_position',
        'ics_subject',
        'ics_reply_text',
        'ics_reply_date',
        'ics_reply_name',
        'ics_reply_position',
    ];

    protected function casts(): array
    {
        return [
            'format' => MessageFormat::class,
            'role' => MessageRole::class,
            'precedence' => MessagePrecedence::class,
            'hx_code' => HxCode::class,
            'is_sm_message' => 'boolean',
            'filed_at' => 'datetime',
            'ics_reply_date' => 'datetime',
        ];
    }

    public function eventConfiguration(): BelongsTo
    {
        return $this->belongsTo(EventConfiguration::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
