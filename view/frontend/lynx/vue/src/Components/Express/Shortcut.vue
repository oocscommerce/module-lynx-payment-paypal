<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useCheckoutStore } from 'OOCS_LynxCheckout/stores/checkoutStore'
import { useFormKeyStore } from 'OOCS_LynxTheme/stores/global/formKeyStore'
import { useMessagesStore } from 'OOCS_LynxTheme/stores/global/messagesStore'

// The PayPal Express shortcut button, the node an admin puts in the checkout's `express` slot above the steps

const props = withDefaults(defineProps<Lynx.PaypalExpressShortcut>(), {
    enabled: false,
    labels: () => ({}),
    sdkUrl: '',
    styles: () => ({}),
    dataAttributes: () => ({}),
    getTokenUrl: '',
    onAuthorizeUrl: '',
    startUrl: '',
    imageUrl: '',
    inContext: false,
})

const checkout = useCheckoutStore()
const formKey = useFormKeyStore()
const messages = useMessagesStore()

const buttonEl = ref<HTMLElement | null>(null)
const setupError = ref('')
// In-context only: false until PayPal's JS has drawn the button, with a skeleton standing in meanwhile
const ready = ref(false)

let buttons: { close?: () => void; render: (el: HTMLElement | null) => Promise<void> } | null = null
// The SDK swallows exceptions from createOrder and onApprove and fires onError with none of their detail
let errorShown = false

// The same namespace the payment step's renderer loads this SDK under
const SDK_NAMESPACE = 'paypalExpressSdk'

/** The PayPal JS SDK, loaded once; a later mount finds it on the namespaced global. */
function loadSdk(): Promise<void> {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    if ((window as any)[SDK_NAMESPACE]) {
        return Promise.resolve()
    }

    return new Promise((resolvePromise, reject) => {
        const script = document.createElement('script')
        script.src = props.sdkUrl
        script.setAttribute('data-namespace', SDK_NAMESPACE)
        for (const [name, value] of Object.entries(props.dataAttributes)) {
            if (value) script.setAttribute(name, value)
        }
        // The page's nonce, over the one the props carry: they came in a response of their own (deferred)
        if (checkout.cspNonce) script.setAttribute('data-csp-nonce', checkout.cspNonce)
        script.onload = () => resolvePromise()
        script.onerror = () => reject(new Error(props.labels.sdkFailed ?? ''))
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

/**
 * Where the controller sends the browser tells what happened
 */
async function follow(redirectUrl: string) {
    if (!redirectUrl.includes('/review')) await checkout.orderPlaced()
    window.location.assign(redirectUrl)
}

// The redirect branch's button, drawn in PayPal's palette when the admin picked a colour
const COLOR: Record<string, string> = {
    gold: 'bg-[#FFC439] text-[#003087] hover:bg-[#F2BA36]',
    blue: 'bg-[#0070BA] text-white hover:bg-[#005EA6]',
    silver: 'bg-[#EEEEEE] text-[#003087] hover:bg-[#DCDCDC]',
    white: 'bg-base-100 text-[#003087] border-(length:--border) border-solid border-[#DCDCDC] hover:bg-[#F5F5F5]',
    black: 'bg-[#2C2E2F] text-white hover:bg-[#1A1C1D]',
}
// PayPal's own three shapes, with their own radii rather than the theme's button token
const SHAPE: Record<string, string> = {
    rect: 'rounded-sm',
    pill: 'rounded-full',
    sharp: 'rounded-none',
}

/** True when the admin wants PayPal's own image rather than a button in their palette. */
const asImage = computed(() => props.button_style === 'image')

const buttonClass = computed(() =>
    [
        'w-full h-11 border-none cursor-pointer text-base font-bold transition',
        COLOR[props.button_style] ?? COLOR.gold,
        SHAPE[props.shape] ?? SHAPE.rect,
    ].join(' ')
)

/** Redirect branch: leave for PayPal through core's own shortcut action. */
function startRedirect() {
    window.location.assign(props.startUrl)
}

onMounted(async () => {
    // The redirect branch needs no SDK: its button is plain markup below.
    if (!props.enabled || !props.inContext) return

    if (!props.sdkUrl || !props.getTokenUrl || !props.onAuthorizeUrl) {
        setupError.value = props.labels.unavailable ?? ''
        return
    }

    try {
        await loadSdk()

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const paypal = (window as any)[SDK_NAMESPACE]
        const styles = props.styles as Record<string, string | undefined>
        buttons = paypal.Buttons({
            // The admin's checkout-page styling, as SmartButtonConfig resolved it.
            style: {
                ...(styles.layout ? { layout: styles.layout } : {}),
                ...(styles.color ? { color: styles.color } : {}),
                ...(styles.shape ? { shape: styles.shape } : {}),
                ...(styles.label ? { label: styles.label } : {}),
            },
            createOrder: async () => {
                errorShown = false
                try {
                    // button=1: the shortcut flow
                    const result = await post(props.getTokenUrl, { button: '1' })
                    if (!result.token) {
                        throw new Error(result.error_message || (props.labels.startFailed ?? ''))
                    }
                    return result.token
                } catch (error) {
                    messages.add('error', (error as Error).message)
                    errorShown = true
                    throw error
                }
            },
            onApprove: async (data: { orderID: string; payerID: string }) => {
                const result = await post(props.onAuthorizeUrl, {
                    paymentToken: data.orderID,
                    payerId: data.payerID,
                })
                if (!result.success || !result.redirectUrl) {
                    messages.add('error', result.error_message || (props.labels.paymentFailed ?? ''))
                    errorShown = true
                    return
                }

                await follow(result.redirectUrl)
            },
            // Fallback for SDK-level failures only: a specific message from createOrder or onApprove has already been shown
            onError: () => {
                if (!errorShown) messages.add('error', props.labels.paymentFailed ?? '')
                errorShown = false
            },
        })
        await buttons!.render(buttonEl.value)
        ready.value = true
    } catch {
        setupError.value = props.labels.loadFailed ?? ''
    }
})

onBeforeUnmount(() => buttons?.close?.())
</script>

<template>
    <div v-if="enabled">
        <p v-if="setupError" class="text-sm text-error">{{ setupError }}</p>
        <template v-else-if="inContext">
            <!-- Skeleton the shape of the button until PayPal has drawn it -->
            <div v-if="!ready" class="l-skeleton h-11 rounded-full" aria-hidden="true"></div>
            <div v-show="ready" ref="buttonEl"></div>
        </template>
        <!-- Redirect branch -->
        <button
            v-else-if="asImage"
            type="button"
            class="p-0 bg-transparent border-none cursor-pointer"
            :title="labels.checkoutWith"
            @click="startRedirect"
        >
            <img :src="imageUrl" :alt="labels.checkoutWith" class="block h-11 w-auto max-w-full" />
        </button>
        <button v-else type="button" :class="buttonClass" @click="startRedirect">
            {{ label || labels.checkoutWith }}
        </button>
    </div>
</template>
