<template>
    <button type="button" @click="handleOrderAction()" :aria-label="actionLabel" id="void-button" class="flex-shrink-0 w-1/4 flex items-center font-bold cursor-pointer justify-center text-white border-box-edge hover:bg-red-600 flex-auto">
        <i class="mr-2 text-2xl lg:text-xl las la-trash"></i> 
        <span class="text-lg hidden md:inline lg:text-2xl">{{ shortActionLabel }}</span>
    </button>
</template>
<script>
import { __ } from '~/libraries/lang';
import { Popup } from '~/libraries/popup';
import nsPosConfirmPopupVue from '~/popups/ns-pos-confirm-popup.vue';

export default {
    props: [ 'order', 'settings' ],
    computed: {
        shortActionLabel() {
            if ( this.order.id == null ) {
                return __( 'Empty' );
            }

            return this.order.payment_status === 'hold' ? __( 'Delete' ) : __( 'Annul' );
        },
        actionLabel() {
            if ( this.order.id == null ) {
                return __( 'Empty Cart' );
            }

            return this.order.payment_status === 'hold'
                ? __( 'Delete Order' )
                : __( 'Void Order' );
        },
    },
    methods: {
        handleOrderAction() {
            if ( this.order.id != null ) {
                POS.voidOrder( this.order );
                return;
            }

            Popup.show( nsPosConfirmPopupVue, {
                title: this.actionLabel,
                focusConfirm: true,
                message: __( 'Would you like to remove all products from the cart?' ),
                onAction: ( action ) => {
                    if ( action ) {
                        POS.reset();
                    }
                },
            });
        },
    }
}
</script>
