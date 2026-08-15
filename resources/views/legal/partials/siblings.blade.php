<div class="legal-siblings">
    <a href="{{ route('legal.privacy') }}" @if(request()->routeIs('legal.privacy')) aria-current="page" @endif>Privacy Policy</a>
    <a href="{{ route('legal.terms') }}" @if(request()->routeIs('legal.terms')) aria-current="page" @endif>Terms of Service</a>
    <a href="{{ route('legal.cookies') }}" @if(request()->routeIs('legal.cookies')) aria-current="page" @endif>Cookie Policy</a>
</div>
