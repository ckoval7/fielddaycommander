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
use App\Models\OperationalPeriod;
use App\Services\EventContextService;
use App\Services\IcsFormBuilder;
use App\Services\OperationalPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ICS forms for the event in context.
 *
 * The ICS-205, 211 and 213 endpoints return JSON that the browser uses to
 * fill FEMA's own fillable PDFs. FEMA does not publish an ICS-309, so the
 * communications log is a print page laid out like the standard form.
 *
 * Every form takes ?period=id for the operational period it covers, and
 * falls back to the period under way (or the whole event without periods).
 */
class IcsFormController extends Controller
{
    public function __construct(
        protected EventContextService $eventContextService,
        protected IcsFormBuilder $builder,
        protected OperationalPeriodService $periods,
    ) {}

    public function ics205(Request $request): JsonResponse
    {
        $config = $this->configuration();

        return response()->json($this->builder->ics205($config, $request->user(), $this->period($request, $config)));
    }

    public function ics205a(Request $request): JsonResponse
    {
        $config = $this->configuration();

        return response()->json($this->builder->ics205a($config, $request->user(), $this->period($request, $config)));
    }

    public function ics211(Request $request): JsonResponse
    {
        $config = $this->configuration();

        return response()->json($this->builder->ics211($config, $request->user(), $this->period($request, $config)));
    }

    public function ics213(Event $event, Message $message): JsonResponse
    {
        abort_unless($message->belongsToEvent($event) && $message->format === MessageFormat::Ics213, 404);

        return response()->json($this->builder->ics213($message));
    }

    /**
     * The ICS-309 for one net (?net=id), or for every net at the event,
     * with the entries logged during the operational period.
     */
    public function ics309(Request $request): View
    {
        $config = $this->configuration();
        $period = $this->period($request, $config);
        $net = $request->filled('net')
            ? Net::forEvent($config->id)->with(['band', 'mode'])->findOrFail($request->integer('net'))
            : null;

        return view('ics.309', [
            'config' => $config,
            'event' => $config->event,
            'net' => $net,
            'period' => $period,
            'entries' => NetLogEntry::forEvent($config->id)
                ->when($net, fn ($query) => $query->where('net_id', $net->id))
                ->when($period->exists, fn ($query) => $query
                    ->where('logged_at', '>=', $period->start_time)
                    ->where('logged_at', '<', $period->end_time))
                ->with('net')
                ->orderBy('logged_at')
                ->orderBy('id')
                ->get(),
            'preparedBy' => $this->builder->personName($request->user()),
        ]);
    }

    protected function period(Request $request, EventConfiguration $config): OperationalPeriod
    {
        return $this->periods->resolve($config, $request->filled('period') ? $request->integer('period') : null);
    }

    protected function configuration(): EventConfiguration
    {
        return $this->eventContextService->getEventConfiguration() ?? abort(404);
    }
}
