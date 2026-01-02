(function () {
    function register() {
        const registry = window.wc?.blocksRegistry || window.wc?.wcBlocksRegistry;
        if (!registry?.registerPaymentMethod) return false;

        const wp = window.wp || {};
        const __ = wp.i18n?.__ || ((s) => s);
        const el = wp.element?.createElement;
        const useState = wp.element?.useState;
        const useRef = wp.element?.useRef;
        const useEffect = wp.element?.useEffect;

        if (!el || !useState || !useRef || !useEffect) return false;

        const isValidPhone = (v) => /^\d{9}$/.test(String(v || ''));

        const iconUrl =
            window.camooPayIconUrl ||
            window.location.origin +
            '/wp-content/plugins/camoo-pay-for-ecommerce/includes/assets/images/camoo-pay.png';

        const iconMoMoUrl =
            window.camooPayMoMoIconUrl ||
            window.location.origin +
            '/wp-content/plugins/camoo-pay-for-ecommerce/includes/assets/images/online_momo.png';

        const label = el(
            'span',
            {
                style: {
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: '8px',
                },
            },
            el('img', {
                src: iconUrl,
                alt: __('CamooPay', 'camoo-pay-for-ecommerce'),
                style: {
                    height: '20px',
                    width: 'auto',
                    display: 'inline-block',
                },
            }),
            el(
                'span',
                null,
                __('Mobile Money', 'camoo-pay-for-ecommerce')
            )
        );

        const Content = ({ eventRegistration, emitResponse }) => {
            const [phone, setPhone] = useState('');
            const phoneRef = useRef('');

            // keep ref in sync with state (NO re-render)
            const onChange = (e) => {
                const v = e?.target?.value || '';
                phoneRef.current = v;
                setPhone(v);
            };

            /**
             * IMPORTANT:
             * Register onPaymentSetup ONCE
             */
            useEffect(() => {
                if (!eventRegistration?.onPaymentSetup) return;

                return eventRegistration.onPaymentSetup(() => {
                    const value = phoneRef.current.trim();

                    if (!isValidPhone(value)) {
                        return {
                            type: emitResponse.responseTypes.ERROR,
                            message: __(
                                'Mobile Money number is required (9 digits).',
                                'camoo-pay-for-ecommerce'
                            ),
                        };
                    }

                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {
                                camoo_pay_phone_number: value,
                            },
                        },
                    };
                });
            }, []); // EMPTY DEPENDENCY ARRAY — critical

            return el(
                'div',
                { className: 'camoo-pay-fields' },

                el('p', {
                    dangerouslySetInnerHTML: {
                        __html: __(
                            'Pay using <strong>Mobile Money</strong> (CAMOO PAY)',
                            'camoo-pay-for-ecommerce'
                        ),
                    },
                }),

                el(
                    'div',
                    { className: 'camoo-pay-input-row' },

                    el('img', {
                        src: iconMoMoUrl,
                        alt: 'Online Mobile Money',
                        title: __('Pay with Cameroon Orange or MTN Mobile Money', 'camoo-pay-for-ecommerce'),
                        style: { width: '36px', height: '36px' },
                    }),

                    el('input', {
                        type: 'tel',
                        inputMode: 'numeric',
                        pattern: '[0-9]{9}',
                        maxLength: 9,
                        required: true,
                        value: phone,
                        onChange,
                        placeholder: __(
                            'Enter your Mobile Money number',
                            'camoo-pay-for-ecommerce'
                        ),
                        className: 'input-text camoo-pay-phone-input',
                        style: { flex: 1, padding: '8px' },
                    })
                )
            );
        };

        registry.registerPaymentMethod({
            name: 'wc_camoo_pay',
            label,
            ariaLabel: __('Mobile Money', 'camoo-pay-for-ecommerce'),
            content: el(Content),
            edit: el(Content),
            canMakePayment: () => true,
            supports: { features: ['products'] },
        });

        return true;
    }

    if (!register()) {
        document.addEventListener('DOMContentLoaded', register);
    }
})();
