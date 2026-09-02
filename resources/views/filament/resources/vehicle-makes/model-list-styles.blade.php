<style>
    .vehicle-model-list.fi-fo-table-repeater tbody tr {
        grid-template-columns: minmax(0, 1fr) auto !important;
        align-items: center;
        gap: 0.5rem;
        min-height: 2.75rem;
        padding: 0.5rem 1rem;
    }

    .vehicle-model-list.fi-fo-table-repeater tbody td {
        min-width: 0;
        padding: 0;
    }

    .vehicle-model-list.fi-fo-table-repeater .fi-fo-table-repeater-actions {
        justify-content: flex-end;
    }

    .vehicle-model-actions .fi-ac {
        align-items: flex-end;
    }

    .vehicle-model-actions .vehicle-model-add-button {
        height: 2.25rem;
        min-height: 2.25rem;
        max-height: 2.25rem;
        white-space: nowrap;
    }

    .vehicle-model-add-row .fi-grid {
        grid-template-columns: minmax(0, 1fr) max-content !important;
        gap: 1rem;
    }

    .vehicle-model-add-row .fi-grid > * {
        grid-column: auto !important;
    }

    @media (max-width: 26.25rem) {
        .vehicle-model-add-row .fi-grid {
            grid-template-columns: minmax(0, 1fr) !important;
            gap: 0.75rem;
        }

        .vehicle-model-actions,
        .vehicle-model-actions .fi-ac,
        .vehicle-model-actions .vehicle-model-add-button {
            width: 100%;
        }

        .vehicle-model-actions .vehicle-model-add-button {
            justify-content: center;
        }
    }
</style>
