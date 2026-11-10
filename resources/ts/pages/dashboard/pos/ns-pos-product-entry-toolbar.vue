<template>
	<div :class="embedded ? 'flex-auto min-w-64' : 'w-full'" @keydown.esc="searchValue = ''">
		<div :class="embedded ? 'border-0 rounded-none' : 'border rounded'"
			class="flex overflow-hidden bg-input-background border-input-edge text-fontcolor">
			<div class="ns-button">
				<button :title="__('Search for products.')" @click="focusSearch()"
					class="w-10 h-10 border-r border-input-edge outline-hidden hover:bg-input-button-hover">
					<i class="las la-search"></i>
				</button>
			</div>
			<div>
				<button :title="__('Toggle merging similar products.')" @click="posToggleMerge()"
					:class="settings.ns_pos_items_merge ? 'bg-input-button-active' : ''"
					class="outline-hidden w-10 h-10 bg-box-background border-input-edge border-r hover:bg-input-button-hover">
	                        <i class="las la-compress-arrows-alt"></i>
				</button>
			</div>
			<input ref="search" v-model="searchValue" type="search"
				:aria-label="__('Search Product')" :placeholder="__('Search Products...')"
				class="min-w-0 flex-auto bg-input-background px-2 text-fontcolor" />
			<div class="ns-button">
				<button v-if="showPinnedProducts" type="button" @click="openPinnedProducts()"
					class="shrink-0 border-l px-3 h-10 font-semibold">
					<i class="las la-thumbtack mr-1"></i>{{ __('Pinned Products') }}
				</button>
			</div>
		</div>
		<div v-if="searchValue.trim()" class="border-t bg-box-background text-fontcolor" aria-live="polite">
			<p v-if="isSearching" class="p-2">{{ __('Searching...') }}</p>
			<p v-else-if="searchError" class="p-2">{{ searchError }}</p>
			<p v-else-if="products.length === 0" class="p-2">{{ __('No products found.') }}</p>
			<button v-for="product in products" :key="product.id" type="button" @click="selectProduct(product)"
				class="flex w-full items-center gap-3 border-b px-3 py-2 text-left hover:bg-input-button-hover">
				<span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded">
					<img v-if="product.imageUrl" :src="product.imageUrl" alt="" class="h-full w-full object-cover" />
					<i v-else class="las la-image text-xl" aria-hidden="true"></i>
				</span>
				<span class="min-w-0 flex-auto">
					<span class="block">{{ product.name }}</span>
					<small v-if="product.category" class="text-soft-secondary">{{ product.category.name }}</small>
				</span>
				<span v-if="product.priceLabel" class="shrink-0 font-semibold">{{ product.priceLabel }}</span>
			</button>
		</div>
	</div>
</template>
<script lang="ts">
import { nsHttpClient } from '../../../bootstrap';
import { __ } from '~/libraries/lang';
import { nsCurrency } from '~/filters/currency';
import nsPosConfirmPopupVue from '~/popups/ns-pos-confirm-popup.vue';
import nsPosPinnedProductsPopup from '~/popups/ns-pos-pinned-products-popup.vue';
import type { Subscription } from 'rxjs';

interface SearchProduct {
	id: number;
	name: string;
	accurate_tracking?: number | string;
	category?: { name: string } | null;
	galleries?: { featured: boolean; url: string }[];
	unit_quantities?: { sale_price: number; sale_price_gross: number; sale_price_net: number }[];
}

type SearchResult = SearchProduct & { imageUrl?: string; priceLabel: string };

export default {
	name: 'ns-pos-product-entry-toolbar',
	props: {
		embedded: { type: Boolean, default: false },
		showPinnedProducts: { type: Boolean, default: false }
	},
	emits: ['search-active'],
	data() {
		return {
			searchValue: '',
			products: [] as SearchResult[],
			isSearching: false,
			searchError: '',
			settings: {},
			settingsSubscriber: null
		};
	},
	watch: {
		searchValue(value: string, _previous: string, onCleanup: (cleanup: () => void) => void) {
			const search = value.trim();
			this.$emit('search-active', search.length > 0);
			this.products = [];
			this.searchError = '';
			this.isSearching = search.length > 0;
			if (!search) return;

			let request: Subscription | undefined;
			const timer = setTimeout(() => {
				request = nsHttpClient.post('/api/products/search', { search, include_sku: false }).subscribe({
					next: (products: SearchProduct[]) => {
						const options = POS.options.getValue();
						const priceField = options.ns_pos_vat === 'disabled' ? 'sale_price'
							: options.ns_pos_prefered_price === 'gross_prices' ? 'sale_price_gross' : 'sale_price_net';

						this.products = products.map((product) => {
							const image = product.galleries?.find((gallery) => gallery.featured) ?? product.galleries?.[0];
							const prices = (product.unit_quantities ?? []).map((unit) => Number(unit[priceField])).filter(Number.isFinite);
							const min = Math.min(...prices);
							const max = Math.max(...prices);
							const priceLabel = prices.length === 0 ? '' : min === max
								? nsCurrency(min) : `${nsCurrency(min)} – ${nsCurrency(max)}`;

							return { ...product, imageUrl: image?.url, priceLabel };
						});
						this.isSearching = false;
					},
					error: (error) => {
						this.searchError = error.message;
						this.isSearching = false;
					}
				});
			}, 250);
			onCleanup(() => {
				clearTimeout(timer);
				request?.unsubscribe();
			});
		}
	},
	mounted() {
		this.settingsSubscriber = POS.settings.subscribe((settings) => {
			this.settings = settings;
		});
		for (const shortcut in nsShortcuts) {
			if (shortcut === 'ns_pos_keyboard_quick_search') {
				nsHotPress
					.create('search-popup')
					.whenNotVisible(['.is-popup', '#product-search'])
					.whenPressed(nsShortcuts[shortcut] !== null ? nsShortcuts[shortcut].join('+') : null, (event) => {
						event.preventDefault();
						this.focusSearch();
					});
			}
			if (shortcut === 'ns_pos_keyboard_toggle_merge') {
				nsHotPress
					.create('toggle-merge')
					.whenNotVisible(['.is-popup'])
					.whenPressed(nsShortcuts[shortcut] !== null ? nsShortcuts[shortcut].join('+') : null, (event) => {
						event.preventDefault();
						this.posToggleMerge();
					});
			}
		}
	},
	unmounted() {
		this.settingsSubscriber?.unsubscribe();
		nsHotPress.destroy('search-popup');
		nsHotPress.destroy('toggle-merge');
	},
	methods: {
		__,
		focusSearch() {
			this.$refs.search.focus();
		},
		openPinnedProducts() {
			Popup.show(nsPosPinnedProductsPopup);
		},
		posToggleMerge() {
			POS.set('ns_pos_items_merge', !this.settings.ns_pos_items_merge);
		},
		selectProduct(product: SearchProduct) {
			if (Number(product.accurate_tracking) === 1) {
				Popup.show(nsPosConfirmPopupVue, {
					title: __('Unable to add the product'),
					message: __(`The product "{product}" can't be added from a search field, as "Accurate Tracking" is enabled. Would you like to learn more ?`).replace('{product}', product.name),
					onAction: (action) => {
						if (action) {
							window.open('https://my.nexopos.com/en/documentation/troubleshooting/accurate-tracking', '_blank');
						}
					}
				});
				return;
			}

			this.searchValue = '';
			POS.addToCart(product);
		}
	}
};
</script>
