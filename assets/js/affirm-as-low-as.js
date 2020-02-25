/* globals jQuery, affirmOptions */

/**
 * Backward compatible.
 */
var affirmAsLowAsOptions = affirmOptions;

jQuery(document).ready(
    function ( $ ) {

            /**
             * Get current amount from `data-amount`.
             *
             * @return {number} Amount.
             */
        function getAmount()
        {
            return $('#learn-more').data('amount')
        }

            /**
             * Update the amount.
             *
             * This will update the `data-amount` attribute in Monthly Payment Messaging
             * element.
             *
             * @param {number} amount - New amount in cents.
             */
        function updateAmount( amount )
        {
            if (! isAffirmExists() ) {
                return;
            }

            $('#learn-more').attr('data-amount', amount);

            if ('function' === typeof affirm.ui.refresh ) {
                affirm.ui.refresh();
            }
        }

            /**
             * Get the minimum amount allowed by Affirm.
             *
             * @see {@link https://docs.affirm.com/Partners/Email_Service_Providers/Monthly_Payment_Messaging_API#Collect_the_loan_details|Collect the loan details}
             *
             * @return {number} Minimum allowed amount in cents.
             */
        function getMinimumAllowedAmount()
        {
            return parseInt(affirmOptions.minimum, 10);
        }

            /**
             * Get the maximum amount allowed by Affirm.
             *
             * @see {@link https://docs.affirm.com/Partners/Email_Service_Providers/Monthly_Payment_Messaging_API#Collect_the_loan_details|Collect the loan details}
             *
             * @return {number} Maximum allowed amount in cents.
             */
        function getMaximumAllowedAmount()
        {
            return parseInt(affirmOptions.maximum, 10);
        }

            /**
             * Init support for composite product.
             */
        function initCompositeProductSupport()
        {
            var composite_data = $('.composite_data');
            if (composite_data.length ) {
                composite_data.on('wc-composite-initializing', compositeUpdateAffirmMonthlyPaymentMessaging);
            }
        }

            /**
             * Update amount when component selection is changed in composite product.
             *
             * @param {object} event - Event.
             * @param {object} composite - Composite object.
             */
        function compositeUpdateAffirmMonthlyPaymentMessaging( event, composite )
        {
            $(document.body).off('found_variation', '.variations_form', onVariationUpdated);

            var updateAmount = onComponentUpdated.bind({ composite: composite });

            composite.actions.add_action('component_selection_changed', updateAmount, 99);
            composite.actions.add_action('component_quantity_changed', updateAmount, 99);
        }

            /**
             * Update amount when composite component is updated.
             *
             * @callback onComponentUpdated
             */
        function onComponentUpdated()
        {
            var totals = this.composite.api.get_composite_totals();

            if ('object' !== typeof totals ) {
                return;
            }
            if (! totals.price ) {
                return;
            }

            updateAmount(totals.price * 100);
        }

            /**
             * Update amount based on variation price.
             *
             * @param {object} event - Event.
             * @param {object} variation - Variation properties.
             *
             * @callback onVariationUpdated
             */
        function onVariationUpdated( event, variation )
        {
            updateAmount(variation.display_price * 100)
        }

            /**
             * Update amount when shipping cost is updated.
             *
             * @callback onShippingMethodUpdated
             */
        function onShippingMethodUpdated()
        {
            updateAmount(getAmount());
        }

            /**
             * Update amount when cart total is updated.
             *
             * @callback onCartTotalsUpdated
             */
        function onCartTotalsUpdated()
        {
            // The DOM is rendered again so need to call init again.
            init();

            updateAmount(getAmount());
        }

            /**
             * Check if Affirm and its dependencies exist.
             *
             * @return {bool} Returns true if Affirm and its dependencies exist.
             */
        function isAffirmExists()
        {
            return (
            'undefined' !== typeof affirm
            &&
            'undefined' !== typeof affirmOptions
            &&
            $('#learn-more').length
            );
        }

            /**
             * Init.
             */
        function init()
        {
            if (isAffirmExists() ) {
                // For a roduct, monitor for the customer changing the variation
                $(document.body).on('found_variation', '.variations_form', onVariationUpdated);

                // For a cart, monitor for changes from shipping cost as well
                $(document.body).on('updated_shipping_method', onShippingMethodUpdated);

                // Support updated price in composite product.
                initCompositeProductSupport();
            }
        }

            init();

            $(document.body).on('updated_cart_totals', onCartTotalsUpdated);

    } 
);
