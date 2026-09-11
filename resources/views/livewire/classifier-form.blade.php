{{--
    Plain Blade, deliberately.

    No components from marque/deck are used here. Spec #83 found that Blade
    resolves components at compile time, so a class_exists() guard around
    another package's component still throws wherever that package is absent.
    Taking a UI-kit dependency would make the engine uninstallable by an
    API-only consumer, so this owns its markup instead.

    Unstyled by design — consumers publish these views (--tag=taxonomy-views)
    and style them to match their own frontend.
--}}
<div>
    <div>
        <label for="taxonomy-content-type">Type</label>

        <select id="taxonomy-content-type" wire:model.live="contentType">
            <option value="">Choose…</option>

            @foreach ($types as $name => $type)
                <option value="{{ $name }}">{{ $type->label }}</option>
            @endforeach
        </select>

        @error('contentType')
            <p>{{ $message }}</p>
        @enderror
    </div>

    @if ($definition)
        {{--
            One select per level, but only as deep as the path has been filled
            in — that is what makes it a cascade rather than a wall of selects.

            Each is free-text-capable via its datalist: a tracker's first 2008
            game has no 2008 term yet, and requiring an admin to pre-create
            every season before anyone can upload would make this useless on a
            new tracker.
        --}}
        @foreach ($definition->levels as $level)
            @if (isset($this->options[$level->name]))
                <div>
                    <label for="taxonomy-level-{{ $level->name }}">{{ $level->label }}</label>

                    <input
                        id="taxonomy-level-{{ $level->name }}"
                        type="text"
                        list="taxonomy-options-{{ $level->name }}"
                        wire:model.live="path.{{ $level->name }}"
                    >

                    <datalist id="taxonomy-options-{{ $level->name }}">
                        @foreach ($this->options[$level->name] as $option)
                            <option value="{{ $option }}"></option>
                        @endforeach
                    </datalist>

                    @error('path.'.$level->name)
                        <p>{{ $message }}</p>
                    @enderror
                </div>
            @endif
        @endforeach

        @error('path')
            <p>{{ $message }}</p>
        @enderror

        @foreach ($definition->facets as $facet)
            <div>
                <label for="taxonomy-facet-{{ $facet }}">{{ ucfirst(str_replace('_', ' ', $facet)) }}</label>

                {{--
                    Multiple by default: a Netflix-style rip carries a dozen
                    subtitle tracks, and cardinality is a definition-declared
                    constraint rather than a storage one.
                --}}
                <select id="taxonomy-facet-{{ $facet }}" multiple wire:model="facets.{{ $facet }}">
                    @foreach ($this->facetOptions[$facet] ?? [] as $value)
                        <option value="{{ $value }}">{{ $value }}</option>
                    @endforeach
                </select>
            </div>
        @endforeach

        <div>
            {{--
                The grouping key, entered by hand for now. Core stores it and
                never interprets it; an entity picker that would derive it
                automatically is deferred with the rest of the entity-field
                machinery (Spec #103: "the key ships; the field machinery may
                not").
            --}}
            <label for="taxonomy-grouping-key">Grouping key <span>(optional)</span></label>
            <input id="taxonomy-grouping-key" type="text" wire:model="groupingKey">
        </div>

        <button type="button" wire:click="save">Save classification</button>
    @endif
</div>
