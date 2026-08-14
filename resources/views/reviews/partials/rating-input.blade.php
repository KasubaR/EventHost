{{--
    Five radios rendered 5→1 and flipped back with `flex-direction: row-reverse`
    in reviews.css. That ordering is what lets `input:checked ~ label` light up
    every star to the left of the chosen one with no JavaScript.

    @param string $name   field name
    @param string $id     unique prefix — this partial repeats once per event
    @param int|null $value currently selected rating
--}}
<div class="rev-stars-input">
    @for ($star = 5; $star >= 1; $star--)
        <input type="radio"
               id="{{ $id }}-rating-{{ $star }}"
               name="{{ $name }}"
               value="{{ $star }}"
               @checked((int) old($name, $value) === $star)
               required>
        <label for="{{ $id }}-rating-{{ $star }}">
            <i class="fa-solid fa-star" aria-hidden="true"></i>
            <span class="rev-sr-only">{{ $star }} {{ Str::plural('star', $star) }}</span>
        </label>
    @endfor
</div>
