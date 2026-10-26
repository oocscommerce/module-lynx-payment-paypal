<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useCheckoutStore } from 'OOCS_LynxCheckout/stores/checkoutStore'
import { useCustomerDataStore } from 'OOCS_LynxTheme/stores/global/customerDataStore'
import { useFormKeyStore } from 'OOCS_LynxTheme/stores/global/formKeyStore'
import { useMessagesStore } from 'OOCS_LynxTheme/stores/global/messagesStore'

// Renderer of paypal_express (Magento_Paypal)

const props = defineProps<{
    config: {
        // Every string the renderer shows, translated by the config class on the server.
        labels?: Record<string, string>
        sdkUrl?: string
        styles?: Record<string, unknown>
        dataAttributes?: Record<string, string>
        getTokenUrl?: string
        onAuthorizeUrl?: string
        startUrl?: string
        inContext?: boolean
    }
    placeOrder: (additionalData: Record<string, unknown>) => Promise<boolean>
    validate: () => boolean
}>()

const checkout = useCheckoutStore()
const customerData = useCustomerDataStore()
const formKey = useFormKeyStore()
const messages = useMessagesStore()

const buttonEl = ref<HTMLElement | null>(null)
const setupError = ref('')
// In-context only: false until PayPal's JS has drawn the button; a skeleton stands in meanwhile
const ready = ref(false)

// Renderer contract: the button is the action, and `loaded` says when PayPal has drawn it.
defineExpose({ ownsAction: true, loaded: ready })

let buttons: { close?: () => void; render: (el: HTMLElement | null) => Promise<void> } | null = null
// The SDK swallows exceptions from createOrder/onApprove and fires onError with none of their detail
let errorShown = false

/** Redirect branch: leave for PayPal; core's return controller places the order. */
async function startRedirect() {
    if (!props.validate()) return
    try {
        await checkout.savePaymentInformation()
    } catch (error) {
        messages.add('error', (error as Error).message)
        return
    }
    window.location.assign(props.config.startUrl!)
}

// A namespace of its own (data-namespace), so this SDK is exposed at window[SDK_NAMESPACE] instead of window.paypal
const SDK_NAMESPACE = 'paypalExpressSdk'

/** The PayPal JS SDK, loaded once; later mounts find it on the namespaced global. */
function loadSdk(): Promise<void> {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    if ((window as any)[SDK_NAMESPACE]) {
        return Promise.resolve()
    }

    return new Promise((resolvePromise, reject) => {
        const script = document.createElement('script')
        script.src = props.config.sdkUrl!
        script.setAttribute('data-namespace', SDK_NAMESPACE)
        for (const [name, value] of Object.entries(props.config.dataAttributes ?? {})) {
            if (value) script.setAttribute(name, value)
        }
        // The page's nonce, over the one the config carries: the config came with the step's props
        if (checkout.cspNonce) script.setAttribute('data-csp-nonce', checkout.cspNonce)
        script.onload = () => resolvePromise()
        script.onerror = () => reject(new Error(props.config.labels?.sdkFailed ?? ''))
        document.head.appendChild(script)
    })
}

/** POST to one of core's express AJAX controllers, form-encoded with the form key. */
async function post(
    url: string,
    params: Record<string, string>
): Promise<{ token?: string; success?: boolean; error_message?: string; redirectUrl?: string }> {
    const body = new URLSearchParams({ ...params, form_key: formKey.ensure() })
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body,
    })
    return (await response.json()) as {
        token?: string
        success?: boolean
        error_message?: string
        redirectUrl?: string
    }
}

onMounted(async () => {
    // The redirect branch needs no SDK: its button is plain markup below.
    if (!props.config.inContext) return
    if (!props.config.sdkUrl || !props.config.getTokenUrl || !props.config.onAuthorizeUrl) {
        setupError.value = props.config.labels?.unavailable ?? ''
        return
    }

    try {
        await loadSdk()

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const paypal = (window as any)[SDK_NAMESPACE]
        const styles = props.config.styles ?? {}
        buttons = paypal.Buttons({
            // The admin's checkout-page styling, as SmartButtonConfig resolved it.
            style: {
                ...(styles.layout ? { layout: styles.layout } : {}),
                ...(styles.color ? { color: styles.color } : {}),
                ...(styles.shape ? { shape: styles.shape } : {}),
                ...(styles.label ? { label: styles.label } : {}),
            },
            // The popup only opens over a form the checkout would accept.
            onClick: (_data: unknown, actions: { resolve: () => void; reject: () => void }) =>
                props.validate() ? actions.resolve() : actions.reject(),
            createOrder: async () => {
                errorShown = false
                try {
                    // What Luma runs before asking for the token (set-payment-information)
                    await checkout.savePaymentInformation()
                    // button=0: the quote's own addresses stand; the popup only approves.
                    const result = await post(props.config.getTokenUrl!, { button: '0' })
                    if (!result.token) {
                        throw new Error(result.error_message || (props.config.labels?.startFailed ?? ''))
                    }
                    return result.token
                } catch (error) {
                    messages.add('error', (error as Error).message)
                    errorShown = true
                    throw error
                }
            },
            onApprove: async (data: { orderID: string; payerID: string }) => {
                const result = await post(props.config.onAuthorizeUrl!, {
                    paymentToken: data.orderID,
                    payerId: data.payerID,
                })
                if (!result.success || !result.redirectUrl) {
                    messages.add('error', result.error_message || (props.config.labels?.paymentFailed ?? ''))
                    errorShown = true
                    return
                }

                // Where the controller sends us tells what happened: the review page means the order is NOT placed yet
                const redirectUrl = result.redirectUrl
                if (!redirectUrl.includes('/review')) {
                    checkout.clearDraft()
                    await customerData.reload(['cart'])
                }
                window.location.assign(redirectUrl)
            },
            // Fallback for SDK-level failures only: a specific message from createOrder/onApprove has already been shown
            onError: () => {
                if (!errorShown) messages.add('error', props.config.labels?.paymentFailed ?? '')
                errorShown = false
            },
        })
        await buttons!.render(buttonEl.value)
        ready.value = true
    } catch {
        setupError.value = props.config.labels?.loadFailed ?? ''
    }
})

onBeforeUnmount(() => buttons?.close?.())
</script>

<template>
    <div>
        <p v-if="setupError" class="text-sm text-error">{{ setupError }}</p>
        <template v-else-if="config.inContext">
            <!-- Skeleton the shape of the button until PayPal has drawn it -->
            <div v-if="!ready" class="l-skeleton h-11 max-w-xs rounded-full" aria-hidden="true"></div>
            <div v-show="ready" ref="buttonEl" class="max-w-xs"></div>
        </template>
        <!-- Redirect branch: Luma's wording above PayPal's brand-gold button. -->
        <div v-else class="grid gap-3 justify-items-start">
            <p class="text-base text-base-content/80">{{ config.labels?.redirectNote }}</p>
            <button
                type="button"
                class="w-full max-w-xs h-11 rounded-button border-none cursor-pointer bg-[#FFC439] text-base font-bold text-[#003087] transition hover:bg-[#F2BA36]"
                @click="startRedirect"
            >
                {{ config.labels?.continueTo }}
            </button>
        </div>
    </div>
</template>
