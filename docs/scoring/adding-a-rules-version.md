# Adding a New Scoring Rules Version

This is the checklist we follow **every year** when ARRL publishes Field Day (or Winter Field Day) rule updates. It does not apply to the one-time creation of the versioning system itself — see the implementation plan for that.

## When this applies

ARRL typically announces Field Day rule tweaks in the spring. Symptoms that we need a new rules version:

- Per-mode point values changed (e.g. CW from 2pt → 3pt).
- Power multiplier thresholds or values changed (e.g. QRP ceiling moves from 5W to 10W).
- A bonus was added, removed, retuned, or its eligible operating classes changed.
- The GOTA point value, coach threshold, or youth bonus formula changed.

If ARRL changes anything that affects a *number* or *formula* in scoring, we make a new rules version. We never edit a frozen year.

## The golden rule

**Never modify a `FieldDayYYYY` class, or `bonus_types`/`mode_rule_points` rows with `rules_version=YYYY`, after it has shipped.** Doing so retroactively alters historical scores for every event pinned to that year. Add a new class and new DB rows for the new year; leave old ones untouched.

## Checklist

Assume ARRL has published the 2027 rules and the current codebase has `FieldDay2025` and `FieldDay2026`. You are adding `FieldDay2027`.

### 1. Capture the diff against the most recent year

Read the ARRL rule PDF. Make a short written comparison against the last year we shipped (e.g. 2026). List every changed number and formula. Drop it into the PR description.

### 2. Create the new ruleset class

```php
// app/Scoring/Rules/FieldDay2027.php
namespace App\Scoring\Rules;

class FieldDay2027 extends FieldDay2026
{
    public function id(): string      { return 'FD-2027'; }
    public function version(): string { return '2027'; }

    // Override only the methods ARRL changed. Inherit the rest.
    // Example: if ARRL moved the QRP ceiling from 5W to 10W:
    //
    //   protected const QRP_WATT_CEILING = 10;
    //
    // Example: if ARRL changed GOTA coach threshold from 10 to 15:
    //
    //   public function gotaCoachThreshold(): int { return 15; }
}
```

Extend the closest-in-time predecessor (almost always `FieldDayYYYY-1`). Do **not** extend `FieldDay2025` and skip years — you lose intervening changes.

### 3. Register the new class in the factory

```php
// app/Scoring/RuleSetFactory.php
protected array $registry = [
    'FD' => [
        '2025' => FieldDay2025::class,
        '2026' => FieldDay2026::class,
        '2027' => FieldDay2027::class,   // add this line
    ],
];
```

Never remove old entries. Historical events continue to resolve to their frozen rulesets.

### 4. Seed `bonus_types` rows for the new version

Write a migration. **Do not update the seeder** — seeders run on fresh databases and the existing bonus rows must remain as-is for any already-installed 2025/2026 data.

```php
// database/migrations/YYYY_MM_DD_NNNNNN_seed_bonus_types_for_2027.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $fdEventTypeId = DB::table('event_types')->where('code', 'FD')->value('id');

        // Copy every 2026 row forward, then apply ARRL's diff.
        $rows = DB::table('bonus_types')
            ->where('event_type_id', $fdEventTypeId)
            ->where('rules_version', '2026')
            ->get();

        foreach ($rows as $row) {
            $new = (array) $row;
            unset($new['id'], $new['created_at'], $new['updated_at']);
            $new['rules_version'] = '2027';

            // Apply ARRL 2027 diffs here:
            // if ($row->code === 'youth_participation') {
            //     $new['base_points'] = 25;     // was 20
            //     $new['max_occurrences'] = 6;  // was 5
            // }
            // if ($row->code === 'some_deprecated_bonus') {
            //     continue;                     // skip — no longer awarded
            // }

            DB::table('bonus_types')->insert([
                ...$new,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Insert any brand-new bonuses ARRL introduced in 2027:
        // DB::table('bonus_types')->insert([
        //     'event_type_id' => $fdEventTypeId,
        //     'rules_version' => '2027',
        //     'code' => 'new_bonus_code',
        //     ...
        // ]);
    }

    public function down(): void
    {
        DB::table('bonus_types')->where('rules_version', '2027')->delete();
    }
};
```

### 5. Seed `mode_rule_points` overrides (only if ARRL changed per-mode points)

```php
DB::table('mode_rule_points')->insert([
    'event_type_id' => $fdEventTypeId,
    'rules_version' => '2027',
    'mode_id' => DB::table('modes')->where('name', 'CW')->value('id'),
    'points' => 3,  // ARRL changed CW from 2 to 3 for 2027
    'created_at' => now(),
    'updated_at' => now(),
]);
```

Only insert rows for modes where the points differ from the fallback (`modes.points_fd`). If ARRL did not change point values, skip this step entirely.

### 6. Write tests against published ARRL numbers

Create `tests/Unit/Scoring/FieldDay2027Test.php`. For each rule ARRL changed, assert the new value directly against `FieldDay2027`. Do not loop over other years — those are frozen and already covered.

```php
test('FieldDay2027 GOTA coach threshold matches ARRL 2027 rules', function () {
    expect((new \App\Scoring\Rules\FieldDay2027)->gotaCoachThreshold())->toBe(15);
});
```

Plus one parity test for everything *not* overridden — confirms it inherits cleanly from the predecessor:

```php
test('unchanged rules match 2026 values', function () {
    $r = new \App\Scoring\Rules\FieldDay2027;
    $base = new \App\Scoring\Rules\FieldDay2026;

    expect($r->youthPointsPerYouth())->toBe($base->youthPointsPerYouth())
        ->and($r->emergencyPowerMaxTransmitters())->toBe($base->emergencyPowerMaxTransmitters());
    // Add lines for every rule that is NOT changing this year.
});
```

### 7. Set the default for new events

Does `EventFactory`'s default `rules_version = (string) $year` still do the right thing? Usually yes. If we need the current calendar year's ruleset to be used for drafts (e.g. someone creates a 2027 event in late 2026), confirm the `Event::creating` observer in `app/Observers/EventObserver.php` picks up `$event->year`. Update only if the year→ruleset mapping needs a manual override.

### 8. Run the scoring test suite

```bash
php artisan test --compact --filter=scoring
php artisan test --compact --filter=EventConfigurationTest
```

Every year's tests must stay green. If an older year's test fails because of your change, you edited something frozen — revert and put the change in `FieldDay2027` instead.

### 9. PR checklist

Before opening the PR, confirm:

- [ ] No file under `app/Scoring/Rules/FieldDayYYYY.php` (for any `YYYY < 2027`) was modified.
- [ ] No existing `bonus_types` or `mode_rule_points` rows were updated; only new rows with `rules_version='2027'` were inserted.
- [ ] `BonusTypeSeeder` was **not** edited.
- [ ] `FieldDay2027` is registered in `RuleSetFactory`.
- [ ] Tests cover every ARRL-changed rule.
- [ ] PR description links the ARRL 2027 rules PDF and summarizes the diff.

### 10. Merge timing

Ideally merge **before** any event pinned to `rules_version='2027'` is created. The factory has a soft-fallback safety net: a 2027 event with no registered `FieldDay2027` resolves to the newest registered version (`FieldDay2026`) and logs `scoring.rules_version_fallback`. That keeps demo and pre-announcement testing unblocked, but the warning means the scores on that event are computed against the *previous* year's rules — not what you want for a real submission. Check logs before submitting.

`UnknownRuleSet` is still thrown if the event type has **no** registered rulesets at all (e.g. someone creates an `XYZ` event type with no ruleset class).

## Where scoring lives (reference map)

| Concern | Source of truth |
|---|---|
| Per-contact mode points | `Mode::points_fd` (default) overridden by `mode_rule_points` rows |
| GOTA flat points | `RuleSet::gotaPointsPerContact()` |
| GOTA coach threshold/bonus | `RuleSet::gotaCoachThreshold()` / `::gotaCoachBonus()` |
| Power multiplier | `RuleSet::powerMultiplier(PowerContext)` |
| Youth bonus formula | `RuleSet::youthMaxCount()` / `::youthPointsPerYouth()` |
| Emergency power cap | `RuleSet::emergencyPowerMaxTransmitters()` |
| Satellite/emergency/public-location bonus values & eligible classes | `bonus_types` row via `RuleSet::bonus(code)` |
| Ruleset selection for an event | `RuleSetFactory::forEvent($event)` |
| Per-event pin | `events.rules_version` (immutable once event is locked) |
