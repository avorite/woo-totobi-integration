(function ($) {
	'use strict';

	function parseVariations($form) {
		var raw = $form.attr('data-product_variations');

		if (!raw) {
			return [];
		}

		try {
			return JSON.parse(raw);
		} catch (error) {
			return [];
		}
	}

	function imageForValue(variations, attrName, value) {
		var attrKey = 'attribute_' + attrName;

		for (var i = 0; i < variations.length; i++) {
			var variation = variations[i];

			if (!variation.attributes || variation.attributes[attrKey] !== value || !variation.image || !variation.image.src) {
				continue;
			}

			return variation.image.src;
		}

		return '';
	}

	function labelForValue($form, attrName, value, fallback) {
		var optionText = '';

		$form.find('select[name="attribute_' + attrName + '"] option').each(function () {
			if (String(this.value || '') === String(value || '')) {
				optionText = this.text || '';
				return false;
			}
		});

		return $.trim(optionText || fallback || value);
	}

	function setSwatchLabel($swatch, label) {
		label = $.trim(label || $swatch.attr('data-title') || $swatch.attr('title') || $swatch.attr('aria-label') || $swatch.data('value') || '');

		if (!label) {
			return;
		}

		$swatch.attr({
			'title': label,
			'aria-label': label
		});

		if (!$swatch.attr('data-title')) {
			$swatch.attr('data-title', label);
		}
	}

	function applyImageSwatches() {
		$('.variations_form').each(function () {
			var $form = $(this);
			var variations = parseVariations($form);

			if (!variations.length) {
				return;
			}

			var $woodmartColorSwatches = $form.find('.wd-swatches-product[data-id="pa_kolir"] .wd-swatch');

			$woodmartColorSwatches.closest('tr').addClass('wti-color-variation-row');
			$woodmartColorSwatches.each(function () {
				var $swatch = $(this);
				var value = String($swatch.data('value') || '');
				var src = imageForValue(variations, 'pa_kolir', value);

				setSwatchLabel($swatch, labelForValue($form, 'pa_kolir', value, $swatch.attr('data-title')));

				if (!src) {
					return;
				}

				$swatch.removeClass('wd-text').addClass('wd-bg wd-tooltip wti-image-swatch');
				$swatch.find('.wd-swatch-text, .wd-swatch-bg').remove();
				$swatch.prepend($('<span/>', {
					'class': 'wd-swatch-bg wti-image-swatch-bg',
					'style': 'background-image:url("' + src.replace(/"/g, '&quot;') + '")'
				}));
			});

			buildFallbackImageSwatches($form, variations);
		});
	}

	function buildFallbackImageSwatches($form, variations) {
		var $select = $form.find('select[name="attribute_pa_kolir"]');

		if (!$select.length || $form.find('.wd-swatches-product[data-id="pa_kolir"] .wd-swatch').length || $form.find('.wti-generated-image-swatches').length) {
			return;
		}

		var $wrap = $('<div/>', {
			'class': 'wd-swatches-product wd-swatches-single wd-bg-style-2 wd-text-style-2 wd-dis-style-2 wd-size-default wd-shape-round wti-generated-image-swatches',
			'data-id': 'pa_kolir',
			'role': 'listbox'
		});

		$select.find('option[value!=""]').each(function () {
			var option = this;
			var value = String(option.value || '');
			var label = labelForValue($form, 'pa_kolir', value, option.text || value);
			var src = imageForValue(variations, 'pa_kolir', value);

			if (!src) {
				return;
			}

			var $swatch = $('<div/>', {
				'class': 'wd-swatch wd-bg wd-tooltip wti-image-swatch wd-enabled',
				'data-value': value,
				'data-title': label,
				'aria-label': label,
				'role': 'option',
				'title': label
			}).append($('<span/>', {
				'class': 'wd-swatch-bg wti-image-swatch-bg',
				'style': 'background-image:url("' + src.replace(/"/g, '&quot;') + '")'
			}));

			$wrap.append($swatch);
		});

		if (!$wrap.children().length) {
			return;
		}

		$select.closest('tr').addClass('wti-color-variation-row');
		$select.after($wrap).addClass('wti-color-select-with-images');
		syncFallbackSelection($select, $wrap);

		$wrap.on('click', '.wti-image-swatch', function () {
			var value = String($(this).data('value') || '');

			$select.val(value).trigger('change');
			syncFallbackSelection($select, $wrap);
		});

		$select.on('change', function () {
			syncFallbackSelection($select, $wrap);
		});
	}

	function syncFallbackSelection($select, $wrap) {
		var value = String($select.val() || '');

		$wrap.find('.wti-image-swatch').each(function () {
			var $swatch = $(this);
			var selected = String($swatch.data('value') || '') === value;

			$swatch.toggleClass('wd-active is-selected', selected).attr('aria-selected', selected ? 'true' : 'false');
		});
	}

	function firstAvailableVariation(variations) {
		for (var i = 0; i < variations.length; i++) {
			var variation = variations[i];

			if (variation && variation.attributes && variation.is_in_stock !== false && variation.variation_is_active !== false) {
				return variation;
			}
		}

		return variations.length ? variations[0] : null;
	}

	function selectDefaultVariation($form) {
		var variations = parseVariations($form);
		var variation;
		var changed = false;
		var complete = true;

		if (!variations.length || $form.data('wtiDefaultVariationApplied')) {
			return;
		}

		if ($form.find('input.variation_id').val() && $form.find('input.variation_id').val() !== '0') {
			$form.data('wtiDefaultVariationApplied', true);
			return;
		}

		variation = firstAvailableVariation(variations);

		if (!variation || !variation.attributes) {
			return;
		}

		$.each(variation.attributes, function (attrName, attrValue) {
			var $select;

			if (!attrValue) {
				return;
			}

			$select = $form.find('select[name="' + attrName + '"]');

			if (!$select.length || $select.val()) {
				return;
			}

			$select.val(attrValue);

			if (String($select.val() || '') === String(attrValue)) {
				changed = true;
				syncFallbackSelection($select, $form.find('.wti-generated-image-swatches[data-id="' + attrName.replace(/^attribute_/, '') + '"]'));
			}
		});

		$form.find('select[name^="attribute_"]').each(function () {
			if (!$(this).val()) {
				complete = false;
				return false;
			}
		});

		if (!changed) {
			$form.data('wtiDefaultVariationApplied', true);
			if (complete) {
				$form.find('select').trigger('change');
				$form.trigger('check_variations');
			}
			return;
		}

		$form.data('wtiDefaultVariationApplied', true);
		$form.find('select').trigger('change');
		$form.trigger('check_variations');
	}

	function selectDefaultVariations() {
		$('.variations_form').each(function () {
			selectDefaultVariation($(this));
		});
	}

	function formatPrice(value) {
		var settings = window.wtiFrontend || {};
		var decimals = typeof settings.decimals === 'number' ? settings.decimals : parseInt(settings.decimals || 2, 10);
		var decimalSep = settings.decimalSep || '.';
		var thousandSep = settings.thousandSep || ' ';
		var currency = settings.currency || '';
		var currencyPos = settings.currencyPos || 'right_space';
		var number = Number(value || 0);
		var parts;
		var formatted;

		if (!isFinite(number)) {
			number = 0;
		}

		parts = number.toFixed(decimals).split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandSep);
		formatted = parts.join(decimalSep);

		switch (currencyPos) {
			case 'left':
				return currency + formatted;
			case 'left_space':
				return currency + ' ' + formatted;
			case 'right':
				return formatted + currency;
			case 'right_space':
			default:
				return formatted + ' ' + currency;
		}
	}

	function priceHtml(total, qty) {
		var qtyLabel = window.wtiFrontend && window.wtiFrontend.qtyLabel ? window.wtiFrontend.qtyLabel : 'за %s шт.';

		return '<span class="woocommerce-Price-amount amount wti-dynamic-price-amount"><bdi>' + formatPrice(total) + '</bdi></span>' +
			'<span class="wti-dynamic-price-qty">' + qtyLabel.replace('%s', qty) + '</span>';
	}

	function findPriceTargets() {
		var $targets = $('.single-product .summary .price, .single-product .woocommerce-variation-price .price').filter(':visible');

		if (!$targets.length) {
			$targets = $('.summary .price, .woocommerce-variation-price .price').filter(':visible');
		}

		return $targets.filter(function () {
			return !$(this).closest('.related, .upsells, .cross-sells, .product-grid-item, .wd-product').length;
		});
	}

	function updateDynamicPrice(unitPrice) {
		var $qty = $('form.cart').find('input.qty').first();
		var qty = parseFloat($qty.val() || '1');
		var price = parseFloat(unitPrice || 0);
		var $targets;

		if (!isFinite(qty) || qty < 1) {
			qty = 1;
		}

		if (!isFinite(price) || price <= 0) {
			return;
		}

		$targets = findPriceTargets();

		if (!$targets.length) {
			return;
		}

		$targets.each(function () {
			var $target = $(this);

			if (!$target.data('wtiOriginalPriceHtml')) {
				$target.data('wtiOriginalPriceHtml', $target.html());
			}

			$target.addClass('wti-dynamic-price').html(priceHtml(price * qty, qty));
		});
	}

	function initDynamicQuantityPrice() {
		var currentPrice = window.wtiFrontend ? parseFloat(window.wtiFrontend.productPrice || 0) : 0;

		if (currentPrice > 0) {
			updateDynamicPrice(currentPrice);
		}

		$(document.body).on('found_variation', 'form.variations_form', function (event, variation) {
			if (variation && variation.display_price) {
				currentPrice = parseFloat(variation.display_price);
				updateDynamicPrice(currentPrice);
			}
		});

		$(document.body).on('reset_data hide_variation', 'form.variations_form', function () {
			currentPrice = window.wtiFrontend ? parseFloat(window.wtiFrontend.productPrice || 0) : 0;
			if (currentPrice > 0) {
				updateDynamicPrice(currentPrice);
			}
		});

		$(document).on('input change click', 'form.cart input.qty, form.cart .plus, form.cart .minus', function () {
			window.setTimeout(function () {
				updateDynamicPrice(currentPrice);
			}, 30);
		});
	}

	$(document).ready(function () {
		applyImageSwatches();
		window.setTimeout(selectDefaultVariations, 80);
	});
	$(document).ready(initDynamicQuantityPrice);
	$(document.body).on('wc_variation_form wood-images-loaded', function () {
		applyImageSwatches();
		window.setTimeout(selectDefaultVariations, 80);
	});
})(jQuery);
