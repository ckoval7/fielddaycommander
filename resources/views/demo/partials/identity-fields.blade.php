{{--
    SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
    SPDX-License-Identifier: GPL-3.0-or-later
--}}
<div class="grid grid-cols-2 gap-3" data-demo-identity>
    <label class="form-control">
        <span class="label-text text-sm mb-1">First name</span>
        <input type="text" name="first_name" value="{{ old('first_name') }}" maxlength="50"
               autocomplete="given-name" class="input input-bordered w-full @error('first_name') input-error @enderror">
        @error('first_name') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
    </label>
    <label class="form-control">
        <span class="label-text text-sm mb-1">Last name</span>
        <input type="text" name="last_name" value="{{ old('last_name') }}" maxlength="50"
               autocomplete="family-name" class="input input-bordered w-full @error('last_name') input-error @enderror">
        @error('last_name') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
    </label>
    <label class="form-control col-span-2">
        <span class="label-text text-sm mb-1">Call sign</span>
        <input type="text" name="call_sign" value="{{ old('call_sign') }}" maxlength="12"
               autocapitalize="characters" class="input input-bordered w-full uppercase @error('call_sign') input-error @enderror">
        @error('call_sign') <span class="text-error text-xs mt-1">{{ $message }}</span> @enderror
    </label>
</div>

{{-- Remember what the visitor typed in this browser, so their next demo
     starts prefilled. Values from a failed submission (old input) win. --}}
<script>
(function () {
    var storageKey = 'demo-identity';
    var fields = document.querySelectorAll('[data-demo-identity] input');
    var saved = {};

    try {
        saved = JSON.parse(localStorage.getItem(storageKey)) || {};
    } catch (e) {}

    fields.forEach(function (field) {
        if (field.value === '' && typeof saved[field.name] === 'string') {
            field.value = saved[field.name];
        }
    });

    if (!fields.length || !fields[0].form) {
        return;
    }

    fields[0].form.addEventListener('submit', function () {
        var identity = {};

        fields.forEach(function (field) {
            identity[field.name] = field.value.trim();
        });

        try {
            localStorage.setItem(storageKey, JSON.stringify(identity));
        } catch (e) {}
    });
}());
</script>
