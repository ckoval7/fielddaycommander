<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Controllers;

use App\Enums\MessageFormat;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Message;
use App\Models\Net;
use App\Models\NetLogEntry;
use App\Services\EventContextService;
use App\Services\IcsFormBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ICS forms for the event in context.
 *
 * The ICS-205, 211 and 213 endpoints return JSON that the browser uses to
 * fill FEMA's own fillable PDFs. FEMA does not publish an ICS-309, so the
 * communications log is a print page laid out like the standard form.
 */
class IcsFormController extends Controller
{
    public function __construct(
        protected EventContextService $eventContextService,
        protected IcsFormBuilder $builder,
    ) {}

    public function ics205(Request $request): JsonResponse
    {
        return response()->json($this->builder->ics205($this->configuration(), $request->user()));
    }

    public function ics205a(Request $request): JsonResponse
    {
        return response()->json($this->builder->ics205a($this->configuration(), $request->user()));
    }

    public function ics211(Request $request): JsonResponse
    {
        return response()->json($this->builder->ics211($this->configuration(), $request->user()));
    }

    public function ics213(Event $event, Message $message): JsonResponse
    {
        abort_unless($message->belongsToEvent($event) && $message->format === MessageFormat::Ics213, 404);

        return response()->json($this->builder->ics213($message));
    }

    /**
     * The ICS-309 for one net (?net=id), or for every net at the event.
     */
    public function ics309(Request $request): View
    {
        $config = $this->configuration();
        $net = $request->filled('net')
            ? Net::forEvent($config->id)->with(['band', 'mode'])->findOrFail($request->integer('net'))
            : null;

        return view('ics.309', [
            'config' => $config,
            'event' => $config->event,
            'net' => $net,
            'entries' => NetLogEntry::forEvent($config->id)
                ->when($net, fn ($query) => $query->where('net_id', $net->id))
                ->with('net')
                ->orderBy('logged_at')
                ->orderBy('id')
                ->get(),
            'preparedBy' => $this->builder->personName($request->user()),
        ]);
    }

    protected function configuration(): EventConfiguration
    {
        return $this->eventContextService->getEventConfiguration() ?? abort(404);
    }
}
