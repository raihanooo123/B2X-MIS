<?php

namespace App\Domain\Storefront;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Cms\SearchIndexing;
use App\Models\Role;
use App\Models\SystemConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * 05.15 §3.1 — saving the storefront branding. Admin only (the
 * SystemConfigurationPolicy `manageStorefront` ability, re-checked under the
 * admin role lock as every staff service does), one `system_configurations`
 * row per key, audited as `configuration.storefront_settings_changed` with
 * the changed keys only.
 *
 * `brand.show_powered_by` and `seo.indexing_enabled` (05.11 §4.4) are
 * `value_type = 'bool'`, stored in `value_int` as 0 or 1. The text keys are `value_type = 'text'`, and an empty value
 * deletes the row so the code default applies again.
 */
final class StorefrontSettings
{
    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    /**
     * Current values in the settings form's shape.
     *
     * @return array<string, string|bool|null>
     */
    public static function formValues(): array
    {
        $rows = SystemConfiguration::query()
            ->where('scope', 'global')
            ->whereIn('config_key', [...Branding::TEXT_KEYS, Branding::SHOW_POWERED_BY])
            ->get(['config_key', 'value_text', 'value_int'])
            ->keyBy('config_key');

        $values = [];
        foreach (Branding::TEXT_KEYS as $key) {
            $values[self::field($key)] = $rows->get($key)?->getAttribute('value_text');
        }
        $poweredBy = $rows->get(Branding::SHOW_POWERED_BY)?->getAttribute('value_int');
        $values[self::field(Branding::SHOW_POWERED_BY)] = $poweredBy === null || (int) $poweredBy !== 0;
        $values['indexing_enabled'] = SearchIndexing::switchedOn();

        return $values;
    }

    /**
     * Validation rules, shared with the Filament form.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', 'not_regex:/[\r\n]/'],
            'tagline' => ['nullable', 'string', 'max:160', 'not_regex:/[\r\n]/'],
            'logo_path' => ['nullable', 'string', 'max:255', 'regex:/^branding\/[A-Za-z0-9._-]+\.(png|jpe?g|webp)$/'],
            'primary_colour' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && Colour::isHex($value) && Colour::contrast($value, '#ffffff') < Colour::MIN_CONTRAST_ON_WHITE) {
                    $fail(sprintf('This colour is too light for white text (contrast %.1f:1; at least 4.5:1 is needed). Choose a darker shade.', Colour::contrast($value, '#ffffff')));
                }
            }],
            'support_email' => ['nullable', 'string', 'max:254', 'not_regex:/[\r\n]/', 'email:rfc,filter'],
            'support_phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
            'show_powered_by' => ['required', 'boolean'],
            // 05.11 §4.4: optional, so a save that does not send it leaves it alone.
            'indexing_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input  keyed by form field (`name`, `tagline`, …)
     */
    public function save(User $actor, array $input): void
    {
        $validated = Validator::make($input, self::rules())->validate();

        DB::transaction(function () use ($actor, $validated): void {
            Role::query()->where('code', 'admin')->lockForUpdate()->first();
            $currentActor = User::query()->findOrFail($actor->id);
            Gate::forUser($currentActor)->authorize('manageStorefront', SystemConfiguration::class);

            $existing = SystemConfiguration::query()
                ->where('scope', 'global')
                ->whereIn('config_key', [...Branding::TEXT_KEYS, Branding::SHOW_POWERED_BY])
                ->lockForUpdate()
                ->get()
                ->keyBy('config_key');

            $before = [];
            $after = [];

            foreach (Branding::TEXT_KEYS as $key) {
                $raw = $validated[self::field($key)] ?? null;
                $new = is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
                $row = $existing->get($key);
                $old = $row?->getAttribute('value_text');

                if ($old === $new) {
                    continue;
                }
                $before[$key] = $old;
                $after[$key] = $new;

                if ($new === null) {
                    $row?->delete();
                } else {
                    SystemConfiguration::query()->updateOrCreate(
                        ['config_key' => $key, 'scope' => 'global', 'location_id' => null, 'company_id' => null],
                        ['value_type' => 'text', 'value_int' => null, 'value_text' => $new, 'description' => 'Storefront branding (05.15 §3.1).', 'updated_by_user_id' => $currentActor->id],
                    );
                }
            }

            $poweredBy = (bool) $validated[self::field(Branding::SHOW_POWERED_BY)];
            $oldRow = $existing->get(Branding::SHOW_POWERED_BY);
            $oldPoweredBy = $oldRow === null || (int) $oldRow->getAttribute('value_int') !== 0;
            if ($oldRow === null || $oldPoweredBy !== $poweredBy) {
                SystemConfiguration::query()->updateOrCreate(
                    ['config_key' => Branding::SHOW_POWERED_BY, 'scope' => 'global', 'location_id' => null, 'company_id' => null],
                    ['value_type' => 'bool', 'value_int' => $poweredBy ? 1 : 0, 'value_text' => null, 'description' => 'Storefront branding (05.15 §3.1).', 'updated_by_user_id' => $currentActor->id],
                );
                if ($oldPoweredBy !== $poweredBy) {
                    $before[Branding::SHOW_POWERED_BY] = $oldPoweredBy;
                    $after[Branding::SHOW_POWERED_BY] = $poweredBy;
                }
            }

            if (array_key_exists('indexing_enabled', $validated)) {
                $indexing = (bool) $validated['indexing_enabled'];
                $oldIndexing = SearchIndexing::switchedOn();
                if ($oldIndexing !== $indexing) {
                    SystemConfiguration::query()->updateOrCreate(
                        ['config_key' => SearchIndexing::KEY, 'scope' => 'global', 'location_id' => null, 'company_id' => null],
                        ['value_type' => 'bool', 'value_int' => $indexing ? 1 : 0, 'value_text' => null, 'description' => 'Search engine indexing (05.11 §4.4).', 'updated_by_user_id' => $currentActor->id],
                    );
                    $before[SearchIndexing::KEY] = $oldIndexing;
                    $after[SearchIndexing::KEY] = $indexing;
                }
            }

            if ($after === []) {
                return;
            }

            $this->audit->record(new AuditEntry(
                action: AuditAction::StorefrontSettingsChanged,
                actorType: 'user',
                actorUserId: $currentActor->id,
                before: $before,
                after: $after,
            ));
        });
    }

    /** `brand.support_email` → `support_email`. */
    public static function field(string $key): string
    {
        return substr($key, strlen('brand.'));
    }
}
