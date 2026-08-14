<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/custom-select.css') }}">
    @endpush

    @push('scripts')
        <script src="{{ asset('js/custom-select.js') }}" defer></script>
    @endpush

    <x-slot name="title">FAQs</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Frequently asked questions</h1>
                <p class="dph-sub">Add, edit and remove the questions shown on the homepage and the contact page.</p>
            </div>
        </div>
    </x-slot>

    @php
        // One page, many forms. Only repopulate the form that actually failed
        // validation — otherwise a failed edit would refill the "add" form.
        $oldFor = function (string $context, string $field, mixed $fallback = null, ?int $id = null): mixed {
            $isFailedForm = old('_form') === $context && (string) old('_faq_id') === (string) $id;

            return $isFailedForm ? old($field, $fallback) : $fallback;
        };
    @endphp

    @if (session('status') === 'faq-created')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Question added.</div>
    @elseif (session('status') === 'faq-updated')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Question saved.</div>
    @elseif (session('status') === 'faq-deleted')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Question deleted.</div>
    @endif

    @if ($errors->any())
        <div class="evt-flash admin-tpl-error" role="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="admin-panel-card">
        <h2>Add a question</h2>
        <p class="admin-muted">
            Questions appear in ascending order number, lowest first. Unpublished questions stay here but are hidden
            from the public site. Answers are plain text — HTML is escaped, not rendered.
        </p>

        <form method="post" action="{{ route('admin.faqs.store') }}" class="admin-faq-form admin-mt-sm">
            @csrf
            <input type="hidden" name="_form" value="create">

            <label class="admin-tpl-field admin-faq-field-wide">
                <span>Question</span>
                <input type="text" name="question" value="{{ $oldFor('create', 'question', '') }}" maxlength="255" required>
            </label>

            <label class="admin-tpl-field admin-faq-field-wide">
                <span>Answer</span>
                <textarea name="answer" rows="3" maxlength="2000" required>{{ $oldFor('create', 'answer', '') }}</textarea>
            </label>

            <div class="admin-faq-row-fields">
                <label class="admin-tpl-field">
                    <span>Shown on</span>
                    <select name="placement" data-cs data-cs-size="sm" required>
                        @foreach ($placements as $value => $label)
                            <option value="{{ $value }}" @selected($oldFor('create', 'placement', 'homepage') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="admin-tpl-field">
                    <span>Order</span>
                    <input type="number" name="sort_order" value="{{ $oldFor('create', 'sort_order', 0) }}" min="0" max="999" step="1" required>
                </label>

                <label class="admin-tpl-check admin-faq-check">
                    {{-- Hidden 0 before the checkbox so unticking submits a value; last one wins. --}}
                    <input type="hidden" name="is_published" value="0">
                    <input type="checkbox" name="is_published" value="1" @checked((bool) $oldFor('create', 'is_published', true))>
                    <span>Published</span>
                </label>
            </div>

            <div class="admin-actions admin-mt-sm">
                <button type="submit" class="btn-primary evt-btn-tiny"><i class="fa-solid fa-plus"></i> Add question</button>
            </div>
        </form>
    </div>

    @foreach ($placements as $placementValue => $placementLabel)
        @php $faqs = $faqsByPlacement[$placementValue] ?? collect(); @endphp

        <div class="admin-section-head admin-mt-lg">
            <h2 class="admin-section-title">{{ $placementLabel }}</h2>
            <span class="admin-muted">{{ $faqs->count() }} {{ Str::plural('question', $faqs->count()) }}</span>
        </div>

        @forelse ($faqs as $faq)
            <article class="admin-panel-card admin-faq-card {{ $faq->is_published ? '' : 'is-unpublished' }}">
                <form method="post" action="{{ route('admin.faqs.update', $faq) }}" class="admin-faq-form">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="_form" value="edit">
                    <input type="hidden" name="_faq_id" value="{{ $faq->id }}">

                    <label class="admin-tpl-field admin-faq-field-wide">
                        <span>Question</span>
                        <input type="text" name="question" value="{{ $oldFor('edit', 'question', $faq->question, $faq->id) }}" maxlength="255" required>
                    </label>

                    <label class="admin-tpl-field admin-faq-field-wide">
                        <span>Answer</span>
                        <textarea name="answer" rows="3" maxlength="2000" required>{{ $oldFor('edit', 'answer', $faq->answer, $faq->id) }}</textarea>
                    </label>

                    <div class="admin-faq-row-fields">
                        <label class="admin-tpl-field">
                            <span>Shown on</span>
                            <select name="placement" data-cs data-cs-size="sm" required>
                                @foreach ($placements as $value => $label)
                                    <option value="{{ $value }}" @selected($oldFor('edit', 'placement', $faq->placement, $faq->id) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="admin-tpl-field">
                            <span>Order</span>
                            <input type="number" name="sort_order" value="{{ $oldFor('edit', 'sort_order', $faq->sort_order, $faq->id) }}" min="0" max="999" step="1" required>
                        </label>

                        <label class="admin-tpl-check admin-faq-check">
                            <input type="hidden" name="is_published" value="0">
                            <input type="checkbox" name="is_published" value="1" @checked((bool) $oldFor('edit', 'is_published', $faq->is_published, $faq->id))>
                            <span>Published</span>
                        </label>
                    </div>

                    <div class="admin-actions admin-mt-sm">
                        <button type="submit" class="btn-primary evt-btn-tiny"><i class="fa-solid fa-floppy-disk"></i> Save</button>
                    </div>
                </form>

                <form method="post" action="{{ route('admin.faqs.destroy', $faq) }}" class="admin-faq-delete"
                      data-confirm="Delete “{{ $faq->question }}”? This cannot be undone.">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="evt-btn-outline evt-btn-tiny admin-tpl-remove"><i class="fa-solid fa-trash"></i> Delete</button>
                </form>
            </article>
        @empty
            <p class="admin-muted">No questions for the {{ Str::lower($placementLabel) }} yet — the section is hidden on the public page until you add one.</p>
        @endforelse
    @endforeach
</x-admin-layout>
