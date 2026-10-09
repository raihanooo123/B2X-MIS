<?php

namespace App\Filament\Pages;

use App\Domain\Storefront\Branding;
use App\Domain\Storefront\StorefrontSettings;
use App\Models\SystemConfiguration;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 05.15 §3.1 — the business's storefront branding: name, tagline, logo,
 * brand colour, contact details and the product credit. Admin only
 * (SystemConfigurationPolicy); saved and audited by StorefrontSettings.
 * The legal details in the footer are the seller details printed on
 * invoices (02 §21.3), not edited here.
 *
 * @property Form $form
 */
class StorefrontSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Storefront';

    protected static ?string $title = 'Storefront settings';

    protected static ?string $slug = 'settings/storefront';

    protected static string $view = 'filament.pages.storefront-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Gate::allows('manageStorefront', SystemConfiguration::class);
    }

    public function mount(): void
    {
        $this->form->fill(StorefrontSettings::formValues());
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make('Brand')
                    ->description('Shown in the storefront header, page titles and emails.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Business name')->required()->maxLength(80),
                        TextInput::make('tagline')->maxLength(160)->helperText('One line under the name on the home page.'),
                        FileUpload::make('logo_path')
                            ->label('Logo')
                            ->image()
                            ->disk(Branding::LOGO_DISK)
                            ->directory('branding')
                            ->visibility('public')
                            // No SVG: served from this origin, it could carry script.
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                            ->maxSize(1024)
                            ->helperText('PNG, JPG or WebP, up to 1 MB. Shown about 36 px high. Leave empty to show the name instead.'),
                        ColorPicker::make('primary_colour')
                            ->label('Brand colour')
                            ->regex('/^#[0-9a-fA-F]{6}$/')
                            ->helperText('Buttons and links. It must be dark enough for white text (contrast 4.5:1).'),
                    ]),
                Section::make('Customer contact')
                    ->description('Shown in the header and footer. Leave empty to hide.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('support_email')->label('Email')->email()->maxLength(254),
                        TextInput::make('support_phone')->label('Phone')->tel()->maxLength(20),
                    ]),
                Section::make('Footer')
                    ->schema([
                        Toggle::make('show_powered_by')->label('Show "Powered by B2X MIS · by Raihan"'),
                    ]),
                Section::make('Search engines')
                    ->description('05.11 §4.4. Keep this off until the legal pages and checkout have been reviewed. It only takes effect on the live (production) site.')
                    ->schema([
                        Toggle::make('indexing_enabled')->label('Allow search engines to index the storefront'),
                    ]),
            ]);
    }

    /** @return list<Action> */
    protected function getFormActions(): array
    {
        return [Action::make('save')->label('Save')->submit('save')];
    }

    public function save(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $state = $this->form->getState();
        try {
            (new StorefrontSettings)->save($actor, $state);
        } catch (ValidationException $e) {
            // The service's rules are the authority; show them on the fields.
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => ["data.{$field}" => $messages])->all());
        }

        Notification::make()->success()->title('Storefront settings saved')->send();
    }
}
