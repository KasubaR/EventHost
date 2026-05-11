<button {{ $attributes->merge(['type' => 'submit', 'class' => 'eh-primary-button']) }}>
    {{ $slot }}
</button>
