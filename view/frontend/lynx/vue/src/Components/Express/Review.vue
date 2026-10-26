<script setup lang="ts">
import { nextTick, reactive, ref } from 'vue'
import { useFormKeyStore } from 'OOCS_LynxTheme/stores/global/formKeyStore'

// The PayPal Express review page (paypal/express/review)

type AddressForm = Record<string, string>

const props = withDefaults(defineProps<Lynx.PaypalReview>(), {
    payment: () => ({}),
    billing_address: () => ({}),
    shipping: null,
    items: () => [],
    totals: () => [],
    agreements: () => [],
    urls: () => ({}),
    labels: () => ({}),
})

const formKeyStore = useFormKeyStore()

const submitting = ref(false)
const agreementChecked = reactive<Record<number, boolean>>({})
/** Which agreements' content is unfolded for reading. */
const agreementOpen = reactive<Record<number, boolean>>({})
const errors = reactive<Record<string, string>>({})

/** The address lines the way the checkout summaries print them. */
function addressLines(address: AddressForm): string[] {
    return [
        [address.firstname, address.lastname].filter(Boolean).join(' '),
        [address.street0, address.street1].filter(Boolean).join(', '),
        [address.city, address.region, address.postcode].filter(Boolean).join(', '),
        address.country_id ?? '',
        address.telephone ? `T: ${address.telephone}` : '',
    ].filter(Boolean)
}

/**
 * Method change: classic POST to core's saveShippingMethod, which recollects the totals and redirects back here
 */
function onMethodChange(event: Event) {
    // '#' is a sample preview page's endpoint: nothing to send
    if (props.urls.save_shipping === '#') return
    const select = event.target as HTMLSelectElement
    const form = select.form!
    const field = form.elements.namedItem('form_key')
    if (field instanceof HTMLInputElement) {
        field.value = formKeyStore.ensure()
    }
    form.submit()
}

/**
 * Classic POST to core's placeOrder
 */
function onPlaceOrder(event: Event) {
    Object.keys(errors).forEach(key => delete errors[key])
    for (const agreement of props.agreements) {
        if (agreement.mode === 'manual' && !agreementChecked[agreement.agreement_id]) {
            errors[`agreement_${agreement.agreement_id}`] = props.labels.required ?? ''
        }
    }
    // '#' is a sample preview page's endpoint: nothing to send
    if (Object.keys(errors).length || props.urls.place_order === '#') {
        event.preventDefault()
        // The first field in error takes focus, so its message is read out
        const form = event.currentTarget as HTMLFormElement
        void nextTick(() => form.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus())
        return
    }

    const field = (event.currentTarget as HTMLFormElement).elements.namedItem('form_key')
    if (field instanceof HTMLInputElement) {
        field.value = formKeyStore.ensure()
    }

    submitting.value = true
}
</script>

<template>
    <div class="w-full">
        <h1 class="text-3xl font-semibold text-base-content mb-8">{{ labels.reviewOrder }}</h1>

        <div class="grid gap-10 lg:grid-cols-[1fr_24rem] items-start">
            <!-- What the visitor approved and where it ships -->
            <div class="grid gap-8 sm:grid-cols-2">
                <section>
                    <h2 class="text-base font-semibold text-base-content mb-2">{{ labels.paymentMethod }}</h2>
                    <p class="text-base text-base-content/80">{{ props.payment.title }}</p>
                    <p v-if="props.payment.payer_email" class="text-sm text-base-content/70 mt-1">
                        {{ props.payment.payer_email }}
                    </p>
                    <a
                        v-if="props.urls.edit"
                        :href="props.urls.edit"
                        class="inline-block mt-2 text-sm text-link underline underline-offset-4 transition-colors"
                        >{{ labels.editPayment }}</a
                    >
                </section>

                <section>
                    <h2 class="text-base font-semibold text-base-content mb-2">{{ labels.billingAddress }}</h2>
                    <p
                        v-for="line in addressLines(props.billing_address)"
                        :key="line"
                        class="text-base text-base-content/80 leading-relaxed"
                    >
                        {{ line }}
                    </p>
                </section>

                <template v-if="props.shipping">
                    <section>
                        <h2 class="text-base font-semibold text-base-content mb-2">{{ labels.shippingAddress }}</h2>
                        <p
                            v-for="line in addressLines(props.shipping.address)"
                            :key="line"
                            class="text-base text-base-content/80 leading-relaxed"
                        >
                            {{ line }}
                        </p>
                        <a
                            v-if="props.shipping.can_edit_address && props.urls.edit"
                            :href="props.urls.edit"
                            class="inline-block mt-2 text-sm text-link underline underline-offset-4 transition-colors"
                            >{{ labels.editShipping }}</a
                        >
                    </section>

                    <section>
                        <h2 class="text-base font-semibold text-base-content mb-2">{{ labels.shippingMethod }}</h2>

                        <!-- Changing the method round-trips through core's controller so every total is the server's -->
                        <form v-if="props.shipping.can_edit_method" method="post" :action="props.urls.save_shipping">
                            <input type="hidden" name="form_key" :value="formKeyStore.value" />
                            <select
                                name="shipping_method"
                                :value="props.shipping.current_method"
                                @change="onMethodChange"
                                class="l-select w-full"
                            >
                                <option v-if="!props.shipping.current_method" value="">
                                    {{ labels.pleaseSelect }}
                                </option>
                                <optgroup
                                    v-for="group in props.shipping.groups"
                                    :key="group.carrier"
                                    :label="group.carrier"
                                >
                                    <option
                                        v-for="option in group.options"
                                        :key="option.value || option.label"
                                        :value="option.value"
                                        :disabled="option.disabled"
                                    >
                                        {{ option.label }}
                                    </option>
                                </optgroup>
                            </select>
                        </form>
                        <p v-else class="text-base text-base-content/80">{{ props.shipping.current_label }}</p>
                    </section>
                </template>
            </div>

            <!-- Summary and the commit -->
            <aside class="l-card l-card-border border-base-300 bg-base-100 p-6">
                <h2 class="text-base font-semibold text-base-content mb-4">{{ labels.orderSummary }}</h2>

                <ul class="list-none m-0 p-0 grid gap-2 border-0 border-b border-solid border-base-300 pb-4 mb-4">
                    <li
                        v-for="item in props.items"
                        :key="item.name"
                        class="flex justify-between gap-4 text-sm text-base-content/80"
                    >
                        <span
                            >{{ item.name }} <span class="text-base-content/70">× {{ item.qty }}</span></span
                        >
                        <span class="tabular-nums shrink-0">{{ item.row_total }}</span>
                    </li>
                </ul>

                <dl class="grid gap-1.5 m-0 mb-6">
                    <div
                        v-for="row in props.totals"
                        :key="row.code"
                        class="flex justify-between gap-4"
                        :class="
                            row.code === 'grand_total'
                                ? 'text-base font-semibold text-base-content mt-1'
                                : 'text-sm text-base-content/80'
                        "
                    >
                        <dt>{{ row.label }}</dt>
                        <dd class="m-0 tabular-nums">{{ row.value }}</dd>
                    </div>
                </dl>

                <form method="post" :action="props.urls.place_order" @submit="onPlaceOrder">
                    <input type="hidden" name="form_key" :value="formKeyStore.value" />

                    <!-- Terms and conditions, the same rules as the checkout -->
                    <div v-if="props.agreements.length" class="grid gap-3 mb-5">
                        <div v-for="a in props.agreements" :key="a.agreement_id">
                            <div class="flex items-start gap-3">
                                <input
                                    v-if="a.mode === 'manual'"
                                    type="checkbox"
                                    :name="`agreement[${a.agreement_id}]`"
                                    :value="a.agreement_id"
                                    v-model="agreementChecked[a.agreement_id]"
                                    class="l-checkbox l-checkbox-xs l-checkbox-primary mt-1 shrink-0"
                                    :aria-label="a.checkbox_text || a.name"
                                    :aria-invalid="!!errors[`agreement_${a.agreement_id}`]"
                                    :aria-describedby="
                                        errors[`agreement_${a.agreement_id}`]
                                            ? `agreement-${a.agreement_id}-error`
                                            : undefined
                                    "
                                />
                                <input
                                    v-else
                                    type="hidden"
                                    :name="`agreement[${a.agreement_id}]`"
                                    :value="a.agreement_id"
                                />
                                <button
                                    type="button"
                                    class="p-0 bg-transparent border-none cursor-pointer text-start text-base leading-6 text-link underline underline-offset-4 transition-colors"
                                    :aria-expanded="!!agreementOpen[a.agreement_id]"
                                    @click="agreementOpen[a.agreement_id] = !agreementOpen[a.agreement_id]"
                                >
                                    {{ a.checkbox_text || a.name }}
                                </button>
                            </div>

                            <div
                                v-if="agreementOpen[a.agreement_id]"
                                class="mt-2 p-4 rounded-box border-(length:--border) border-base-300 bg-base-100 overflow-y-auto text-base leading-relaxed text-base-content/80"
                                :style="{ maxHeight: a.content_height || '16rem' }"
                            >
                                <div v-if="a.is_html" v-html="a.content"></div>
                                <p v-else class="whitespace-pre-line">{{ a.content }}</p>
                            </div>

                            <p
                                v-if="errors[`agreement_${a.agreement_id}`]"
                                :id="`agreement-${a.agreement_id}-error`"
                                class="mt-1 text-sm text-error"
                                :class="a.mode === 'manual' ? 'ms-7' : ''"
                            >
                                {{ errors[`agreement_${a.agreement_id}`] }}
                            </p>
                        </div>
                    </div>

                    <button
                        type="submit"
                        :disabled="submitting || (!!props.shipping?.can_edit_method && !props.shipping?.current_method)"
                        class="l-btn l-btn-primary l-btn-lg w-full px-8 uppercase"
                    >
                        {{ submitting ? labels.placingOrder : labels.placeOrder }}
                    </button>
                </form>

                <a
                    v-if="props.urls.cart"
                    :href="props.urls.cart"
                    class="block text-center mt-4 text-sm text-link underline underline-offset-4 transition-colors"
                    >{{ labels.backToCart }}</a
                >
            </aside>
        </div>
    </div>
</template>
