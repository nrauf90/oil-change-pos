@props([
    // Where the DELETE goes.
    'action',
    // What is being destroyed, for the screen reader and the armed label —
    // "Delete invoice INV-20260827-0001", not a row of identical "Delete"s.
    'subject' => null,
    'label' => 'Delete',
])

{{--
    Arm, then confirm — the same two-tap gesture the counter already knows from
    "Clear" on the sale ticket.

    Every screen this sits on is used on a tablet propped up in a workshop, by
    someone with oil on their hands, and every one of these buttons destroys a
    record for good: an invoice out of the day's takings, a part out of the
    catalogue, a receipt out of the drawer reconciliation. One stray touch
    should never be enough.

    Resting, it is an outline: present, findable, but not competing with the
    Edit beside it. Armed, it goes solid red and says what it is about to do,
    then disarms itself after three seconds so a ticket left open on the counter
    is not sitting one accidental brush away from a deletion.
--}}
<form method="POST" action="{{ $action }}"
      x-data="{ armed: false, timer: null }"
      x-on:submit="
          if (! armed) {
              $event.preventDefault();
              armed = true;
              clearTimeout(timer);
              timer = setTimeout(() => { armed = false }, 3000);

              return;
          }

          $event.submitter.disabled = true;
      "
      {{ $attributes }}>
    @csrf
    @method('DELETE')

    <button type="submit"
            class="!px-3 !py-1.5 transition"
            :class="armed ? 'btn-danger' : 'btn-ghost !border-red-200 !text-red-600 hover:!bg-red-50'"
            :aria-label="armed
                ? @js('Confirm deleting '.($subject ?? 'this record'))
                : @js($label.($subject ? ' '.$subject : ''))"
            x-text="armed ? 'Confirm?' : @js($label)">{{ $label }}</button>
</form>
