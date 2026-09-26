<div class="rounded-xl border border-slate-800 bg-slate-900 divide-y divide-slate-800">
    @forelse ($users as $user)
        <div class="flex items-center justify-between px-4 py-3 gap-4">
            <div>
                <p class="font-semibold">{{ $user->displayName() }}</p>
                <p class="text-xs text-slate-500">{{ $user->email }}</p>
                <a href="{{ route('superadmin.wallets.transactions', $user) }}" class="text-xs text-red-400 hover:underline">{{ __('View transactions') }}</a>
            </div>
            <span class="font-semibold text-emerald-400">{{ $currencySymbol }}{{ number_format($user->wallet->main_balance ?? 0, 2) }}</span>
            <div class="flex gap-2">
                <button type="button" class="wallet-credit-btn rounded-lg bg-emerald-600 hover:bg-emerald-500 transition text-sm px-3 py-1"
                        data-name="{{ $user->displayName() }}" data-url="{{ route('superadmin.wallets.credit', $user) }}">{{ __('Credit') }}</button>
                <button type="button" class="wallet-debit-btn rounded-lg bg-red-700 hover:bg-red-600 transition text-sm px-3 py-1"
                        data-name="{{ $user->displayName() }}" data-url="{{ route('superadmin.wallets.debit', $user) }}">{{ __('Debit') }}</button>
            </div>
        </div>
    @empty
        <p class="px-4 py-6 text-sm text-slate-500">{{ __('No matching player accounts.') }}</p>
    @endforelse
</div>

@if ($users->hasPages())
    <div class="mt-4">
        {{ $users->links() }}
    </div>
@endif
