<script setup lang="ts">
// The PayPal Credit button, the node an admin puts in the checkout's `express` slot above the steps

const props = withDefaults(defineProps<Lynx.PaypalExpressBml>(), {
    enabled: false,
    labels: () => ({}),
    startUrl: '',
    imageUrl: '',
    infoImageUrl: '',
    infoUrl: '',
})

/** Leave for PayPal through core's own Credit start action. */
function start() {
    window.location.assign(props.startUrl)
}
</script>

<template>
    <div v-if="enabled" class="grid gap-2 justify-items-start">
        <button
            type="button"
            class="p-0 bg-transparent border-none cursor-pointer"
            :title="labels.checkoutWith"
            @click="start"
        >
            <img :src="imageUrl" :alt="labels.checkoutWith" class="block h-11 w-auto max-w-full" />
        </button>

        <!-- PayPal's own disclosure link for this product, the pair core renders under the button -->
        <a v-if="infoUrl && infoImageUrl" :href="infoUrl" target="_blank" rel="noopener noreferrer">
            <img :src="infoImageUrl" :alt="labels.creditName" class="block h-4 w-auto max-w-full" />
        </a>
    </div>
</template>
