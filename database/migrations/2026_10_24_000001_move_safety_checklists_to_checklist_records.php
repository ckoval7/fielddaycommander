<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move each event's fixed-type safety checklist into checklist records.
 *
 * Every checklist type an event used becomes one checklist, its items keep
 * their order and flags, and its entries become the answers of one
 * event-wide run, so past events look the same. The bonus code and
 * completion rule carry the ARRL gate that used to hang off the type.
 */
return new class extends Migration
{
    /**
     * Old checklist type => the checklist it becomes.
     *
     * @var array<string, array{name: string, template_key: string, bonus_code: string|null, completion_rule: string, shift_roles: list<string>}>
     */
    private const TYPES = [
        'safety_officer' => [
            'name' => 'Safety Officer',
            'template_key' => 'arrl_safety_officer',
            'bonus_code' => 'safety_officer',
            'completion_rule' => 'all_items',
            'shift_roles' => ['Safety Officer'],
        ],
        'site_responsibilities' => [
            'name' => 'Site Responsibilities',
            'template_key' => 'arrl_site_responsibilities',
            'bonus_code' => 'site_responsibilities',
            'completion_rule' => 'required_and_majority',
            'shift_roles' => ['Site Responsibilities'],
        ],
        'briefing' => [
            'name' => 'Volunteer Briefing',
            'template_key' => 'briefing',
            'bonus_code' => null,
            'completion_rule' => 'required_items',
            'shift_roles' => [],
        ],
    ];

    public function up(): void
    {
        if (Schema::hasTable('safety_checklist_items')) {
            $this->moveChecklists();
        }

        Schema::dropIfExists('safety_checklist_entries');
        Schema::dropIfExists('safety_checklist_items');
    }

    /**
     * Removed checklists can't be split back into the old tables; this only
     * restores their empty structure so earlier migrations can roll back.
     */
    public function down(): void
    {
        Schema::create('safety_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_configuration_id')->constrained('event_configurations')->cascadeOnDelete();
            $table->string('checklist_type');
            $table->string('label');
            $table->text('help_text')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['event_configuration_id', 'checklist_type'], 'safety_items_config_type_index');
        });

        Schema::create('safety_checklist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('safety_checklist_item_id')->unique()->constrained('safety_checklist_items')->cascadeOnDelete();
            $table->boolean('is_completed')->default(false);
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    private function moveChecklists(): void
    {
        $groups = DB::table('safety_checklist_items')
            ->whereNull('deleted_at')
            ->select('event_configuration_id', 'checklist_type')
            ->distinct()
            ->orderBy('event_configuration_id')
            ->get();

        foreach ($groups as $group) {
            $type = self::TYPES[$group->checklist_type] ?? null;

            if ($type === null) {
                continue;
            }

            DB::transaction(fn () => $this->moveChecklist((int) $group->event_configuration_id, $group->checklist_type, $type));
        }
    }

    /**
     * @param  array{name: string, template_key: string, bonus_code: string|null, completion_rule: string, shift_roles: list<string>}  $type
     */
    private function moveChecklist(int $eventConfigurationId, string $checklistType, array $type): void
    {
        $now = now();

        $checklistId = DB::table('checklists')->insertGetId([
            'event_configuration_id' => $eventConfigurationId,
            'name' => $type['name'],
            'scope' => 'event',
            'completion_rule' => $type['completion_rule'],
            'bonus_code' => $type['bonus_code'],
            'template_key' => $type['template_key'],
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $shiftRoleIds = DB::table('shift_roles')
            ->where('event_configuration_id', $eventConfigurationId)
            ->whereIn('name', $type['shift_roles'])
            ->whereNull('deleted_at')
            ->pluck('id');

        foreach ($shiftRoleIds as $shiftRoleId) {
            DB::table('checklist_shift_role')->insert(['checklist_id' => $checklistId, 'shift_role_id' => $shiftRoleId]);
        }

        $runId = DB::table('checklist_runs')->insertGetId([
            'checklist_id' => $checklistId,
            'subject_key' => 'event',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $items = DB::table('safety_checklist_items')
            ->leftJoin('safety_checklist_entries', 'safety_checklist_entries.safety_checklist_item_id', '=', 'safety_checklist_items.id')
            ->where('safety_checklist_items.event_configuration_id', $eventConfigurationId)
            ->where('safety_checklist_items.checklist_type', $checklistType)
            ->whereNull('safety_checklist_items.deleted_at')
            ->orderBy('safety_checklist_items.sort_order')
            ->orderBy('safety_checklist_items.id')
            ->select(
                'safety_checklist_items.*',
                'safety_checklist_entries.is_completed',
                'safety_checklist_entries.completed_by_user_id',
                'safety_checklist_entries.completed_at',
                'safety_checklist_entries.notes',
            )
            ->get();

        $answered = 0;
        $requiredTotal = 0;
        $requiredAnswered = 0;
        $lastCompletedAt = null;

        foreach ($items as $item) {
            $itemId = DB::table('checklist_items')->insertGetId([
                'checklist_id' => $checklistId,
                'label' => $item->label,
                'help_text' => $item->help_text,
                'answer_type' => 'checkbox',
                'is_required' => (bool) $item->is_required,
                'is_default' => (bool) $item->is_default,
                'sort_order' => $item->sort_order,
                'created_at' => $item->created_at ?? $now,
                'updated_at' => $item->updated_at ?? $now,
            ]);

            $isCompleted = (bool) $item->is_completed;
            $requiredTotal += $item->is_required ? 1 : 0;

            if ($isCompleted) {
                $answered++;
                $requiredAnswered += $item->is_required ? 1 : 0;
                $lastCompletedAt = max($lastCompletedAt, $item->completed_at);
            }

            if ($isCompleted || filled($item->notes)) {
                DB::table('checklist_answers')->insert([
                    'checklist_run_id' => $runId,
                    'checklist_item_id' => $itemId,
                    'value' => $isCompleted ? '1' : null,
                    'notes' => $item->notes,
                    'answered_by_user_id' => $isCompleted ? $item->completed_by_user_id : null,
                    'answered_at' => $isCompleted ? $item->completed_at : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if ($this->gateMet($type['completion_rule'], $items->count(), $answered, $requiredTotal, $requiredAnswered)) {
            DB::table('checklist_runs')->where('id', $runId)->update(['completed_at' => $lastCompletedAt ?? $now]);
        }
    }

    /**
     * Mirrors ChecklistCompletionRule::isMet() as it stood when this ran.
     */
    private function gateMet(string $rule, int $total, int $answered, int $requiredTotal, int $requiredAnswered): bool
    {
        if ($total === 0) {
            return false;
        }

        return match ($rule) {
            'all_items' => $answered === $total,
            'required_and_majority' => $requiredAnswered === $requiredTotal && $answered > $total / 2,
            default => $requiredAnswered === $requiredTotal && ($requiredTotal > 0 || $answered === $total),
        };
    }
};
