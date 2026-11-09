<script>
import { __ } from '~/libraries/lang';
import { Popup } from '~/libraries/popup';
import nsPosConfirmPopupVue from '~/popups/ns-pos-confirm-popup.vue';

export default {
    name: 'ns-pos-dashboard-button',
    methods: {
        __,
        goToDashboard() {
            const navigate = () => {
                const dashboardUrl = POS.settings.getValue()[ 'urls' ][ 'dashboard_url' ];
                window.onbeforeunload = null;
                document.location = dashboardUrl;
            };

            if ( POS.products.getValue().length === 0 ) {
                navigate();
                return;
            }

            Popup.show( nsPosConfirmPopupVue, {
                title: __( 'Confirm Your Action' ),
                message: __( 'There are products in the cart. Unsaved changes will be lost. Would you like to leave the POS?' ),
                onAction: ( action ) => {
                    if ( action ) {
                        navigate();
                    }
                },
            });
        }
    }
}
</script>
<template>
    <div class="ns-button success">
        <button @click="goToDashboard()" class="rounded shadow flex-shrink-0 h-12 flex items-center px-2 py-1 text-sm">
            <i class="mr-1 text-xl las la-tachometer-alt"></i>
            <span>{{ __( 'Dashboard' ) }}</span>
        </button>
    </div>
</template>
