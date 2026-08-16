@extends('layouts.site')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/ticket-checkout.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/ticket-order-status.js') }}" defer></script>
@endpush

@section('title', 'Order status | '.$order->event->name)

@section('content')
    <article class="tkc-page"
             data-order-status-root
             data-verify-url="{{ route('ticket.orders.verify', $order->order_reference) }}"
             data-order-status="{{ $order->status->value }}"
             data-terminal-status="{{ $order->status->isTerminal() ? '1' : '0' }}">
        <div class="tkc-card tkc-card--narrow">
            @if ($order->isPaid())
                <div class="tkc-result tkc-result--success">
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                    <h1 class="tkc-title">You're all set!</h1>
                    <p class="tkc-muted">Your tickets for {{ $order->event->name }} have been emailed to {{ $order->buyer_email }}.</p>
                </div>

                <div class="tkc-ticket-list">
                    @foreach ($order->tickets as $ticket)
                        <a href="{{ route('tickets.show', $ticket->public_token) }}" class="tkc-ticket-row">
                            <span><i class="fa-solid fa-ticket" aria-hidden="true"></i> Ticket #{{ $ticket->id }}</span>
                            <span class="tkc-ticket-row-cta">View ticket <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                        </a>
                    @endforeach
                </div>
            @elseif ($order->status->isTerminal())
                <div class="tkc-result tkc-result--error">
                    <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                    <h1 class="tkc-title">Payment not completed</h1>
                    <p class="tkc-muted">{{ $order->payment?->failure_reason ?? 'Your order could not be completed. Please try again.' }}</p>
                    <a href="{{ route('events.public.tickets', $order->event->slug) }}" class="btn-primary tkc-submit-btn">Try again</a>
                </div>
            @elseif ($order->status->value === 'payment_processing')
                <div class="tkc-result tkc-result--pending">
                    <i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i>
                    <h1 class="tkc-title">Approve the payment on your phone</h1>
                    <p class="tkc-muted">A mobile money prompt has been sent. Confirm it on your phone to finish buying your tickets.</p>
                </div>
            @else
                <div class="tkc-result tkc-result--pending">
                    <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
                    <h1 class="tkc-title">Starting your payment…</h1>
                    <p class="tkc-muted">We're contacting the payment provider. This usually takes a few seconds — stay on this page.</p>
                </div>
            @endif
        </div>
    </article>
@endsection
