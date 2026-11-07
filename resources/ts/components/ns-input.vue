<template>
    <div class="flex flex-col mb-2 flex-auto ns-input" :class="hasError ? 'has-error' : 'is-pristine'">
        <label v-if="field.label && ( field.label.length > 0)" :for="field.name" class="block leading-5 font-medium"><slot></slot></label>
        <div :class="( field.description || field.errors > 0 ? 'mb-2' : ''  )" class="mt-1 relative overflow-hidden border rounded-md focus:shadow-sm">
            <div v-if="leading" class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <span class="leading sm:text-sm sm:leading-5">
                {{ leading }}
                </span>
            </div>
            <div v-if="field.prefix" class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <span class="prefix sm:text-sm sm:leading-5">
                {{ field.prefix }}
                </span>
            </div>
            <input 
                :name="field.name"
                :disabled="field.disabled" 
                v-model="field.value" 
                :id="field.name" :type="passwordVisible && isPassword ? 'text' : inputType"
                :class="inputClass" class="block w-full sm:text-sm sm:leading-5 h-10" :placeholder="field.placeholder || ''" />
            <div class="absolute inset-y-0 right-0 flex items-center pointer-events-none">
                <span v-if="field.suffix" class="suffix pr-3 sm:text-sm sm:leading-5">
                {{ field.suffix }}
                </span>
                <button
                    v-if="isPassword"
                    type="button"
                    class="pointer-events-auto h-full px-3 cursor-pointer disabled:cursor-not-allowed focus-visible:outline-2 focus-visible:-outline-offset-2"
                    :disabled="field.disabled"
                    :aria-label="__(passwordVisible ? 'Hide Password' : 'Show Password')"
                    @click="passwordVisible = !passwordVisible"
                >
                    <i class="las text-lg" :class="passwordVisible ? 'la-eye-slash' : 'la-eye'" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <ns-field-description :field="field"></ns-field-description>
    </div>
</template>
<script>
import { __ } from '~/libraries/lang';

export default {
    data: () => {
        return {
            passwordVisible: false,
        }
    },
    mounted() {
        // ...
    },
    methods: { __ },
    watch: {
        'field.name'() {
            this.passwordVisible = false;
        },
        inputType() {
            this.passwordVisible = false;
        },
    },
    computed: {
        inputType() {
            return this.type || this.field.type || 'text';
        },
        isPassword() {
            return this.inputType === 'password';
        },
        hasError() {
            if ( this.field.errors !== undefined && this.field.errors.length > 0 ) {
                return true;
            }
            return false;
        },
        disabledClass() {
            return this.field.disabled ? 'ns-disabled cursor-not-allowed' : '';
        },
        inputClass() {
            return [this.disabledClass, this.leadClass, this.isPassword && (this.field.suffix ? 'pr-20' : 'pr-10')];
        },
        leadClass() {
            return this.leading ? 'pl-8' : this.isPassword ? 'pl-4' : 'px-4';
        }
    },
    props: [ 'placeholder', 'leading', 'type', 'field' ],
}
</script>