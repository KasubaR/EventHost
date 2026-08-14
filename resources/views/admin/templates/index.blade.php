<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Templates</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Invitation templates</h1>
                <p class="dph-sub">Upload a preview image for each template and choose which ones are featured on the homepage.</p>
            </div>
        </div>
    </x-slot>

    @if (session('status') === 'template-updated')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Template saved.</div>
    @elseif (session('status') === 'template-image-removed')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Preview image removed. The template is no longer featured.</div>
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
        <p class="admin-muted">
            The homepage shows the {{ $homepageLimit }} featured templates with the lowest order number, and hides the
            whole section when nothing is featured. A template needs a preview image before it can be featured — the
            same image is used on <span class="admin-tpl-inline-code">/templates</span> and in the event wizard.
        </p>
        <p class="admin-muted admin-mt-sm">
            <strong>{{ $featuredCount }}</strong> featured
            @if ($featuredCount > $homepageLimit)
                — only the first {{ $homepageLimit }} appear on the homepage.
            @endif
        </p>
    </div>

    <div class="admin-tpl-grid admin-mt-md">
        @forelse ($templates as $tpl)
            <article class="admin-tpl-card {{ $tpl->is_featured ? 'is-featured' : '' }}">
                <div class="admin-tpl-thumb">
                    @if ($tpl->preview_image_url)
                        <img src="{{ $tpl->preview_image_url }}" alt="{{ $tpl->name }} preview" width="600" height="800" loading="lazy" decoding="async">
                    @else
                        <span class="admin-tpl-thumb-empty"><i class="fa-regular fa-image" aria-hidden="true"></i> No image</span>
                    @endif
                    @if ($tpl->is_featured)
                        <span class="admin-tpl-badge"><i class="fa-solid fa-star" aria-hidden="true"></i> Featured</span>
                    @endif
                </div>

                <div class="admin-tpl-body">
                    <h2 class="admin-tpl-name">{{ $tpl->name }}</h2>
                    <p class="admin-muted">{{ $tpl->slug }}</p>

                    <div class="admin-tpl-tags">
                        @foreach ($tpl->categories as $cat)
                            <span class="admin-tpl-tag">{{ $cat->name }}</span>
                        @endforeach
                        <span class="admin-tpl-tag admin-tpl-tag-tier">{{ $tpl->requiredTier()->label() }}</span>
                        @unless ($tpl->is_active)
                            <span class="admin-tpl-tag admin-tpl-tag-off">Inactive</span>
                        @endunless
                    </div>

                    <form method="post" action="{{ route('admin.templates.update', $tpl) }}" enctype="multipart/form-data" class="admin-tpl-form">
                        @csrf
                        @method('PATCH')

                        <label class="admin-tpl-check">
                            {{-- Hidden 0 before the checkbox so unticking submits a value; last one wins. --}}
                            <input type="hidden" name="is_featured" value="0">
                            <input type="checkbox" name="is_featured" value="1" @checked($tpl->is_featured)>
                            <span>Feature on homepage</span>
                        </label>

                        <label class="admin-tpl-field">
                            <span>Order</span>
                            <input type="number" name="featured_sort_order" value="{{ $tpl->featured_sort_order }}" min="0" max="999" step="1" required>
                        </label>

                        <label class="admin-tpl-field admin-tpl-file">
                            <span>Preview image {{ $tpl->preview_image ? '(replace)' : '' }}</span>
                            <input type="file" name="preview_image" accept="image/jpeg,image/png,image/webp">
                        </label>
                        <p class="admin-muted">JPEG, PNG or WebP up to 4 MB. Cropped to 600×800 WebP.</p>

                        <div class="admin-actions admin-mt-sm">
                            <button type="submit" class="btn-primary evt-btn-tiny"><i class="fa-solid fa-floppy-disk"></i> Save</button>
                            <a href="{{ route('templates.preview', $tpl) }}" class="evt-btn-outline evt-btn-tiny" target="_blank" rel="noopener">Preview</a>
                        </div>
                    </form>

                    @if ($tpl->preview_image)
                        <form method="post" action="{{ route('admin.templates.image.destroy', $tpl) }}" class="admin-mt-sm" data-confirm="Remove the preview image for {{ $tpl->name }}? It will also stop being featured.">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="evt-btn-outline evt-btn-tiny admin-tpl-remove">Remove image</button>
                        </form>
                    @endif
                </div>
            </article>
        @empty
            <p class="admin-muted">No invitation templates exist yet. Run <span class="admin-tpl-inline-code">php artisan db:seed --class=InvitationTemplateSeeder</span>.</p>
        @endforelse
    </div>
</x-admin-layout>
