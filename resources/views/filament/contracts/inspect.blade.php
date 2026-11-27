@php
    /** @var \App\Filament\Resources\Contracts\Pages\InspectContract $this */
    $inspection = $this->inspection();
    $editable = $this->canEdit();
    $departure = $this->departure();
    $isReturn = $this->inspectionType() === \App\Enums\InspectionType::Return;
    // Zones du schéma (vue de dessus, viewBox 200 × 400) : x, y, largeur, hauteur.
    $shapes = [
        'front_bumper' => [40, 8, 120, 22], 'hood' => [45, 32, 110, 70], 'windshield' => [50, 104, 100, 34],
        'roof' => [50, 140, 100, 110], 'rear_window' => [50, 252, 100, 30], 'trunk' => [45, 284, 110, 76],
        'rear_bumper' => [40, 362, 120, 24], 'front_left' => [8, 40, 36, 150], 'rear_left' => [8, 200, 36, 160],
        'front_right' => [156, 40, 36, 150], 'rear_right' => [156, 200, 36, 160],
    ];
    $fuelLabels = ['Vide', '1/8', '1/4', '3/8', '1/2', '5/8', '3/4', '7/8', 'Plein'];
@endphp

<x-filament-panels::page>
    @vite('resources/js/inspection.js')

    <style>
        .eds { display: grid; gap: 1rem; max-width: 52rem; }
        .eds-card { border: 1px solid rgb(229 231 235); border-radius: .75rem; padding: 1rem; background: var(--color-white, #fff); }
        .dark .eds-card { background: rgb(24 24 27); border-color: rgb(63 63 70); }
        .eds-card h2 { font-weight: 600; font-size: 1.05rem; margin-bottom: .75rem; }
        .eds-row { display: flex; flex-wrap: wrap; gap: .75rem; align-items: end; }
        .eds-field { display: flex; flex-direction: column; gap: .25rem; font-size: .9rem; flex: 1 1 12rem; }
        .eds-field input, .eds-field textarea, .eds-field select { border: 1px solid rgb(209 213 219); border-radius: .5rem; padding: .6rem .7rem; font-size: 1.05rem; background: transparent; }
        .eds-btn { min-height: 3rem; padding: .6rem 1rem; border-radius: .6rem; font-weight: 600; border: 1px solid rgb(209 213 219); background: transparent; }
        .eds-btn-primary { background: rgb(37 99 235); border-color: rgb(37 99 235); color: #fff; }
        .eds-btn-danger { color: rgb(185 28 28); border-color: rgb(252 165 165); }
        .eds-btn[disabled] { opacity: .5; }
        .eds-fuel { display: grid; grid-template-columns: repeat(9, minmax(0, 1fr)); gap: .35rem; }
        .eds-fuel button { min-height: 3.25rem; border-radius: .5rem; border: 1px solid rgb(209 213 219); font-size: .8rem; }
        .eds-fuel button.is-on { background: rgb(22 163 74); border-color: rgb(22 163 74); color: #fff; }
        .eds-schema { width: 100%; max-width: 20rem; touch-action: manipulation; }
        .eds-schema rect { fill: rgb(241 245 249); stroke: rgb(100 116 139); stroke-width: 1.5; cursor: crosshair; }
        .eds-schema rect:hover { fill: rgb(219 234 254); }
        .eds-damages { display: grid; gap: .5rem; }
        .eds-damage { display: flex; justify-content: space-between; gap: .5rem; align-items: center; border-bottom: 1px dashed rgb(229 231 235); padding-bottom: .4rem; }
        .eds-photos { display: grid; grid-template-columns: repeat(auto-fill, minmax(9rem, 1fr)); gap: .5rem; }
        .eds-photos img { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border-radius: .5rem; }
        .eds-signature canvas { width: 100%; height: 12rem; border: 2px dashed rgb(148 163 184); border-radius: .6rem; touch-action: none; background: #fff; }
        .eds-compare { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; font-size: .95rem; }
        .eds-badge { display: inline-block; padding: .1rem .5rem; border-radius: 999px; font-size: .75rem; background: rgb(243 244 246); }
        .eds-badge-new { background: rgb(254 226 226); color: rgb(153 27 27); }
        @media (max-width: 640px) { .eds-fuel { grid-template-columns: repeat(5, minmax(0, 1fr)); } .eds-compare { grid-template-columns: 1fr; } }
    </style>

    <div class="eds">
        <div class="eds-card">
            <p><strong>{{ $this->contract()->vehicle->label() }}</strong> · {{ $this->contract()->customer->displayName() }}</p>
            <p style="font-size:.9rem;opacity:.8">
                Départ prévu {{ $this->contract()->starts_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }},
                retour prévu {{ $this->contract()->ends_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }} ·
                {{ $this->contract()->hasUnlimitedMileage() ? 'Kilométrage illimité' : $this->contract()->included_km_per_day.' km par jour' }} ·
                carburant : même niveau au retour
            </p>
            @if ($inspection->isValidated())
                <p class="eds-badge" style="margin-top:.5rem">Validé le {{ $inspection->validated_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }} : lecture seule</p>
            @elseif (! $editable)
                <p class="eds-badge" style="margin-top:.5rem">Lecture seule</p>
            @endif
        </div>

        @if ($isReturn && $departure !== null)
            <div class="eds-card">
                <h2>Comparaison avec le départ</h2>
                <div class="eds-compare">
                    <div>
                        <p><strong>Départ</strong></p>
                        <p>Kilométrage : {{ number_format((int) $departure->mileage, 0, ',', ' ') }} km</p>
                        <p>Carburant : {{ $fuelLabels[$departure->fuel_eighths ?? 0] }}</p>
                        <p>Dommages : {{ $departure->damages->count() }}</p>
                    </div>
                    <div>
                        <p><strong>Retour</strong></p>
                        <p>Kilométrage : {{ $inspection->mileage === null ? '—' : number_format($inspection->mileage, 0, ',', ' ').' km' }}
                            @if ($inspection->mileage !== null) (+{{ number_format($inspection->mileage - (int) $departure->mileage, 0, ',', ' ') }} km) @endif</p>
                        <p>Carburant : {{ $inspection->fuel_eighths === null ? '—' : $fuelLabels[$inspection->fuel_eighths] }}</p>
                        <p>Nouveaux dommages : {{ $inspection->damages->where('is_new', true)->count() }}</p>
                    </div>
                </div>
            </div>
        @endif

        <div class="eds-card">
            <h2>1. Relevés</h2>
            <div class="eds-row">
                <label class="eds-field">Kilométrage (km)
                    <input type="text" inputmode="numeric" wire:model="mileage" @disabled(! $editable) data-test="mileage">
                    @error('mileage') <span style="color:rgb(185 28 28)">{{ $message }}</span> @enderror
                </label>
                @if ($editable)
                    <button type="button" class="eds-btn eds-btn-primary" wire:click="saveReadings">Enregistrer</button>
                @endif
            </div>
            <p style="margin:.75rem 0 .35rem">Carburant</p>
            <div class="eds-fuel">
                @foreach ($fuelLabels as $eighths => $label)
                    <button type="button" wire:click="setFuel({{ $eighths }})" @disabled(! $editable)
                        class="{{ $fuel_eighths === $eighths ? 'is-on' : '' }}">{{ $label }}</button>
                @endforeach
            </div>
            <label class="eds-field" style="margin-top:.75rem">Observations
                <textarea rows="2" wire:model="notes" wire:blur="saveReadings" @disabled(! $editable)></textarea>
            </label>
        </div>

        <div class="eds-card" x-data="window.aviatorInspection.damageSchema($wire)">
            <h2>2. Dommages</h2>
            <p style="font-size:.85rem;opacity:.8;margin-bottom:.5rem">
                Touchez l'endroit du dommage sur le schéma. En gris : dommages déjà présents au dernier retour.
            </p>
            <div class="eds-row" style="align-items:start">
                <svg x-ref="schema" class="eds-schema" viewBox="0 0 200 400" role="img" aria-label="Schéma du véhicule vu de dessus">
                    @foreach ($shapes as $zone => [$sx, $sy, $sw, $sh])
                        <rect x="{{ $sx }}" y="{{ $sy }}" width="{{ $sw }}" height="{{ $sh }}" rx="8"
                            @if ($editable) @click="place($event, '{{ $zone }}')" @endif>
                            <title>{{ \App\Enums\VehicleZone::from($zone)->label() }}</title>
                        </rect>
                    @endforeach
                    @foreach ($inspection->damages as $damage)
                        <circle cx="{{ (float) $damage->x * 2 }}" cy="{{ (float) $damage->y * 4 }}" r="7"
                            fill="{{ $damage->is_new ? 'rgb(220 38 38)' : 'rgb(100 116 139)' }}" stroke="#fff" stroke-width="2" />
                    @endforeach
                    @if ($placing && $x !== null)
                        <circle cx="{{ (float) $x * 2 }}" cy="{{ (float) $y * 4 }}" r="8" fill="none" stroke="rgb(37 99 235)" stroke-width="3" />
                    @endif
                </svg>

                <div style="flex:1 1 16rem">
                    @if ($editable)
                        @if ($placing)
                            <div class="eds-row" style="flex-direction:column;align-items:stretch">
                                <label class="eds-field">Zone
                                    <select wire:model="zone">
                                        @foreach ($this->zones() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="eds-field">Description
                                    <input type="text" wire:model="description" maxlength="255" placeholder="Rayure, bosse, éclat…">
                                    @error('description') <span style="color:rgb(185 28 28)">{{ $message }}</span> @enderror
                                </label>
                                <label class="eds-field">Gravité
                                    <select wire:model="severity">
                                        <option value="">Choisir…</option>
                                        @foreach ($this->severities() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('severity') <span style="color:rgb(185 28 28)">{{ $message }}</span> @enderror
                                </label>
                                <div class="eds-row">
                                    <button type="button" class="eds-btn eds-btn-primary" wire:click="addDamage">Ajouter le dommage</button>
                                    <button type="button" class="eds-btn" wire:click="cancelDamage">Annuler</button>
                                </div>
                            </div>
                        @else
                            <button type="button" class="eds-btn" wire:click="$set('placing', true)">Dommage hors schéma (jantes, intérieur)</button>
                        @endif
                    @endif

                    <div class="eds-damages" style="margin-top:.75rem">
                        @forelse ($inspection->damages as $damage)
                            <div class="eds-damage">
                                <span>
                                    <span class="eds-badge {{ $damage->is_new ? 'eds-badge-new' : '' }}">{{ $damage->is_new ? 'Nouveau' : 'Déjà présent' }}</span>
                                    {{ $damage->zone->label() }} : {{ $damage->description }} ({{ mb_strtolower($damage->severity->label()) }})
                                </span>
                                @if ($editable && $damage->is_new)
                                    <button type="button" class="eds-btn eds-btn-danger" wire:click="removeDamage({{ $damage->id }})">Retirer</button>
                                @endif
                            </div>
                        @empty
                            <p style="opacity:.7">Aucun dommage relevé.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="eds-card" x-data="window.aviatorInspection.photoField($wire)">
            <h2>3. Photos</h2>
            @if ($editable)
                <label class="eds-btn" style="display:inline-flex;align-items:center;gap:.5rem;cursor:pointer">
                    <input type="file" accept="image/*" capture="environment" multiple style="display:none" @change="pick($event)">
                    <span x-text="busy ? 'Envoi en cours…' : 'Prendre une photo'"></span>
                </label>
                <p x-show="error" x-text="error" style="color:rgb(185 28 28)"></p>
            @endif
            <div class="eds-photos" style="margin-top:.75rem">
                @forelse ($inspection->photos as $photo)
                    <div>
                        <img src="{{ $this->photoUrl($photo) }}" alt="Photo de l'état des lieux" loading="lazy">
                        @if ($editable)
                            <button type="button" class="eds-btn eds-btn-danger" style="width:100%;margin-top:.25rem" wire:click="removePhoto({{ $photo->id }})">Retirer</button>
                        @endif
                    </div>
                @empty
                    <p style="opacity:.7">Aucune photo.</p>
                @endforelse
            </div>
        </div>

        <div class="eds-card">
            <h2>4. Signature du client</h2>
            @if ($inspection->signature_path !== null)
                <img src="{{ $this->signatureUrl($inspection) }}" alt="Signature du client" style="max-height:8rem;background:#fff;border-radius:.5rem">
            @elseif ($inspection->customer_absent_reason !== null)
                <p>Client absent : {{ $inspection->customer_absent_reason }}</p>
            @endif

            @if ($editable)
                <div class="eds-signature" x-data="window.aviatorInspection.signatureField($wire)" wire:ignore style="margin-top:.5rem">
                    <canvas x-ref="canvas"></canvas>
                    <div class="eds-row" style="margin-top:.5rem">
                        <button type="button" class="eds-btn eds-btn-primary" @click="save()" :disabled="empty || saving">
                            {{ $inspection->signature_path === null ? 'Enregistrer la signature' : 'Remplacer la signature' }}
                        </button>
                        <button type="button" class="eds-btn" @click="clear()">Effacer</button>
                    </div>
                </div>

                @if ($isReturn)
                    <div class="eds-row" style="margin-top:.75rem">
                        <label class="eds-field">Client absent (retour hors horaires, boîte à clés) : motif
                            <input type="text" wire:model="absentReason" maxlength="255">
                        </label>
                        <button type="button" class="eds-btn" wire:click="markAbsent">Enregistrer l'absence</button>
                    </div>
                @endif
            @endif
        </div>

        @if ($editable && ! $isReturn)
            <div class="eds-card">
                <h2>5. Valider le départ</h2>
                <p style="font-size:.9rem;opacity:.8;margin-bottom:.75rem">
                    Contrôle : relevés et signature, conducteur principal, remise et dérogation approuvées, assurance et visite
                    technique valides. Le véhicule passe « Loué » et l'état des lieux est figé.
                </p>
                <button type="button" class="eds-btn eds-btn-primary" wire:click="validateDeparture" wire:confirm="Valider le départ ? L'état des lieux ne sera plus modifiable.">Valider le départ</button>
            </div>
        @elseif ($editable && $isReturn)
            @php($newDamages = $inspection->damages->where('is_new', true))
            <div class="eds-card">
                <h2>5. Frais et validation du retour</h2>
                <div class="eds-row">
                    <label class="eds-field">Heure de retour déclarée (si différente de maintenant)
                        <input type="datetime-local" wire:model.live.debounce.500ms="returnedAt">
                        @error('returned_at') <span style="color:rgb(185 28 28)">{{ $message }}</span> @enderror
                    </label>
                </div>
                @if ($newDamages->isNotEmpty())
                    <p style="margin:.75rem 0 .35rem">Montant HT des nouveaux dommages (devis ou barème ; plafonné par la franchise)</p>
                    @foreach ($newDamages as $damage)
                        <label class="eds-field">{{ $damage->zone->label() }} : {{ $damage->description }}
                            <input type="text" inputmode="decimal" wire:model.live.debounce.500ms="damageAmounts.{{ $damage->id }}" placeholder="0,000">
                        </label>
                    @endforeach
                @endif
                <label style="display:flex;gap:.5rem;align-items:center;margin-top:.75rem">
                    <input type="checkbox" wire:model="vehicleToRepair" style="width:1.4rem;height:1.4rem"> Véhicule à réparer (il passe « En maintenance »)
                </label>
                @php($preview = $this->returnPreview())
                <div style="margin-top:.75rem">
                    @if (is_string($preview))
                        <p style="opacity:.8">{{ $preview }}</p>
                    @elseif ($preview === [])
                        <p>Aucun frais.</p>
                    @else
                        @foreach ($preview as $line)
                            <p>{{ $line['label'] }} : <strong>{{ $line['amount'] }}</strong> {{ $line['detail'] }}</p>
                        @endforeach
                    @endif
                </div>
                <button type="button" class="eds-btn eds-btn-primary" style="margin-top:.75rem" wire:click="validateReturn"
                    wire:confirm="Valider le retour ? Les frais sont figés et l'état des lieux n'est plus modifiable.">Valider le retour</button>
            </div>
        @endif
    </div>
</x-filament-panels::page>
