{{--
    Plain Blade, same reasoning as the upload form: no components from
    marque/deck, because Blade resolves components at compile time and a
    class_exists() guard around one still throws where that package is absent
    (Spec #83). Unstyled by design — publish with --tag=taxonomy-views and
    restyle.

    Note what this screen does NOT contain: any control that adds, renames or
    removes a LEVEL. Levels come from the definition. An admin populates them.
--}}
<div>
    <h2>Content types</h2>

    <table>
        <thead>
            <tr>
                <th>Type</th>
                <th>Shape</th>
                <th>Version</th>
                <th>Classified</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($this->installed as $row)
                <tr>
                    <td>{{ $row['label'] }} <span>({{ $row['name'] }})</span></td>
                    <td>{{ $row['levels'] }}</td>
                    <td>
                        v{{ $row['version'] }}
                        @if ($row['installed'] && $row['installed'] !== $row['version'])
                            <span>running v{{ $row['installed'] }}</span>
                        @endif
                    </td>
                    <td>{{ $row['classified'] }}</td>
                </tr>

                @if ($row['pending'])
                    <tr>
                        <td colspan="4">
                            {{--
                                Named, not offered as a button. CP5's
                                confirmation is deliberately a command: it
                                reports what it will orphan and how many
                                torrents are affected before anything happens,
                                and that is not a dialog to click through.
                            --}}
                            An update is available (v{{ $row['pending']['from'] }} →
                            v{{ $row['pending']['to'] }}), affecting
                            {{ $row['pending']['affected'] }} torrent(s).

                            @if ($row['pending']['path'])
                                Run <code>marque:taxonomy:upgrade {{ $row['name'] }}</code>
                                to review and apply it.
                            @else
                                It declares no migration path, so it cannot be applied —
                                that is the package author's to fix.
                            @endif
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>

    <h2>Terms</h2>

    <div>
        <label for="taxonomy-admin-type">Content type</label>

        <select id="taxonomy-admin-type" wire:model.live="contentType">
            <option value="">Choose…</option>

            @foreach ($this->types as $name => $type)
                <option value="{{ $name }}">{{ $type->label }}</option>
            @endforeach
        </select>

        @error('contentType')
            <p>{{ $message }}</p>
        @enderror
    </div>

    @if ($this->definition)
        <p>
            {{-- Stated plainly, because it is the thing an admin most needs to
                 understand about this screen. --}}
            Levels are <strong>defined in</strong> the content type's definition file and
            cannot be changed here. Add the values you actually run.
        </p>

        @foreach ($this->definition->levels as $level)
            <section>
                <h3>{{ $level->label }}</h3>

                <ul>
                    @forelse ($this->terms[$level->name] ?? [] as $term)
                        <li>
                            {{ $term->label ?? $term->value }}
                            <button type="button" wire:click="deleteTerm({{ $term->id }})">Remove</button>
                        </li>
                    @empty
                        <li>Nothing yet.</li>
                    @endforelse
                </ul>
            </section>
        @endforeach

        @error('terms')
            <p>{{ $message }}</p>
        @enderror

        <fieldset>
            <legend>Add a value</legend>

            <label for="taxonomy-admin-level">Level</label>
            <select id="taxonomy-admin-level" wire:model="newTerm.level">
                <option value="">Choose…</option>
                @foreach ($this->definition->levels as $level)
                    <option value="{{ $level->name }}">{{ $level->label }}</option>
                @endforeach
            </select>
            @error('newTerm.level')
                <p>{{ $message }}</p>
            @enderror

            <label for="taxonomy-admin-parent">Under</label>
            <select id="taxonomy-admin-parent" wire:model="newTerm.parent">
                <option value="">Top level</option>
                @foreach ($this->terms as $levelName => $terms)
                    @foreach ($terms as $term)
                        <option value="{{ $term->id }}">{{ $levelName }}: {{ $term->label ?? $term->value }}</option>
                    @endforeach
                @endforeach
            </select>

            <label for="taxonomy-admin-value">Value</label>
            <input id="taxonomy-admin-value" type="text" wire:model="newTerm.value">
            @error('newTerm.value')
                <p>{{ $message }}</p>
            @enderror

            <button type="button" wire:click="addTerm">Add</button>
        </fieldset>
    @endif

    <h2>Vocabularies</h2>

    <p>
        Facets are shared across every content type. Drop the values your tracker
        does not carry — an HD-only site has no use for 480p.
    </p>

    @foreach ($this->vocabularies as $name => $values)
        <section>
            <h3>{{ ucfirst(str_replace('_', ' ', $name)) }}</h3>

            <ul>
                @forelse ($values as $value)
                    <li>
                        {{ $value->label ?? $value->value }}
                        <button type="button" wire:click="deleteFacetValue({{ $value->id }})">Remove</button>
                    </li>
                @empty
                    <li>Nothing yet.</li>
                @endforelse
            </ul>
        </section>
    @endforeach

    @error('facets')
        <p>{{ $message }}</p>
    @enderror

    <fieldset>
        <legend>Add a vocabulary value</legend>

        <label for="taxonomy-admin-facet">Facet</label>
        <select id="taxonomy-admin-facet" wire:model="newFacetValue.facet">
            <option value="">Choose…</option>
            @foreach (array_keys($this->vocabularies) as $name)
                <option value="{{ $name }}">{{ ucfirst(str_replace('_', ' ', $name)) }}</option>
            @endforeach
        </select>
        @error('newFacetValue.facet')
            <p>{{ $message }}</p>
        @enderror

        <label for="taxonomy-admin-facet-value">Value</label>
        <input id="taxonomy-admin-facet-value" type="text" wire:model="newFacetValue.value">
        @error('newFacetValue.value')
            <p>{{ $message }}</p>
        @enderror

        <button type="button" wire:click="addFacetValue">Add</button>
    </fieldset>
</div>
