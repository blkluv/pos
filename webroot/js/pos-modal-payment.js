/**
 * JavaScript Handlers for the POS Payment Modal
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

var ppCashPaid_List = new Array();

function ppFormUpdate()
{
	var full = parseFloat(OpenTHC.POS.Cart.full_price);
	var cash = parseFloat($('#payment-cash-incoming').text());
	var need = (full - cash);
	var back = (cash - full);

	console.log('ppFormUpdate(' + cash + ', ' + full + ')');


	if (cash < full) {

		$('#amount-paid-wrap').removeClass('alert-success');
		$('#amount-paid-wrap').addClass('alert-secondary');

		$('#amount-need-wrap').removeClass('alert-danger alert-secondary alert-success');
		$('#amount-need-wrap').addClass('alert-warning');
		$('#amount-need-hint').text('Due:');
		$('#payment-cash-outgoing').text(need.toFixed(2));

	} else if (cash === full) {

		$('#amount-paid-wrap').removeClass('alert-secondary alert-success');
		$('#amount-paid-wrap').addClass('alert-success');

		$('#amount-need-wrap').removeClass('alert-danger alert-secondary alert-success alert-warning');
		$('#amount-need-wrap').addClass('alert-success');
		$('#amount-need-hint').text('Perfect!');
		$('#payment-cash-outgoing').text('0.00');

		// $('#payment-cash-incoming').addClass('text-success').removeClass('text-danger').removeClass('text-warning');

		$('#pos-payment-commit').removeAttr('disabled');

	} else if (cash > full) {

		$('#amount-paid-wrap').removeClass('alert-secondary alert-success');
		$('#amount-paid-wrap').addClass('alert-success');

		// Making Change
		$('#amount-need-wrap').removeClass('alert-danger alert-secondary alert-success');
		$('#amount-need-wrap').addClass('alert-danger');
		$('#amount-need-hint').text('Change:');
		$('#payment-cash-outgoing').text( back.toFixed(2) );

		// $('#payment-cash-incoming').addClass('text-warning').removeClass('text-danger').removeClass('text-success');
		// $('#pp-card-pay').removeClass('text-danger').removeClass('text-success').removeClass('text-warning');

		$('#pos-payment-commit').removeAttr('disabled');

	}

	if (ppCashPaid_List.length == 0) {
		$('#pos-pay-undo').prop('disabled', true);
	} else {
		$('#pos-pay-undo').prop('disabled', false);
	}

}


function ppAddCash(n)
{
	console.log('ppAddCash');

	var full = OpenTHC.POS.Cart.full_price;

	var add = parseFloat( $(n).data('amount') );
	var cur = parseFloat( $('#payment-cash-incoming').text() );
	if (!cur) cur = 0;

	var cash = (cur + add);
	$('#payment-cash-incoming').text( cash.toFixed(2)  );

	var card = full - cash;
	$('#pp-card-pay').val( card.toFixed(2)  );

	ppCashPaid_List.push(add);

	ppFormUpdate();

}

function ppAddCard()
{
	console.log('ppAddCard');

	var need = $('#payment-need').val();
	$('#pp-card-confirm').val(need);

	//Weed.modal('shut');

	// var arg = $('#psi-form').serializeArray();
	// $('#modal-content-wrap').load('/pos/pay', arg, function() {
	// $.post('/pos/pay', arg, function(res) {

	// var x = $('#card-modal').clone();
	// $(x).find('#pp-card-confirm').attr('id', 'pp-card-prompt');
	//Weed.modal( $('#card-modal') );
	//$('#card-modal').show();


	// Weed.modal('#card-modal');
	// $('#pp-card-confirm').val( $('#pp-card-pay').val() );
	// Weed.modal('#card-modal');
	// });
}

$(function() {

	$('.pp-cash').on('click touchend', function(e) {
		ppAddCash(this);
		e.preventDefault();
		e.stopPropagation();
		return false;
	});

	$('.pp-card').on('click touchend', function(e) {
		ppAddCard();
		e.preventDefault();
		e.stopPropagation();
		return false;
	});

	// Focus on Select!
	// $('#payment-cash-incoming').on('focus', function(e) {
	// 	console.log('focus');
	// 	$(this).select();
	// });
	// $('#payment-cash-incoming').on('mouseup', function(e) {
	// 	console.log('mouseup');
	// 	e.preventDefault();
	// 	return false;
	// });

	// $('#payment-cash-incoming').on('keyup', function() {
	// 	ppFormUpdate();
	// });

	// Reset my Form
	$('#pos-pay-undo').on('click', function() {
		$('#payment-cash-incoming').text('0.00');
		$('#pp-card-pay').text('0.00');
		ppFormUpdate();
	});

	$(document.body).on('click', '#pos-card-back', function() {
		//Weed.modal('shut');
	});

	$(document.body).on('click', '#pos-card-done', function() {
		//Weed.modal('shut');
	});

	/**
		Actual Payment Button
	*/
	$('#pos-payment-commit').on('click touchend', function(e) {

		var cash_incoming = $('#payment-cash-incoming').text();
		var cash_outgoing = $('#payment-cash-outgoing').text();

		// Append to existing form to capture all the other existing inputs
		$('#psi-form').attr('action', '/pos/checkout/commit');
		$('#psi-form').append('<input name="a" type="hidden" value="pos-done">');
		$('#psi-form').append(`<input name="cart-id" type="hidden" value="${OpenTHC.POS.Cart.id}">`);
		$('#psi-form').append(`<input name="cart-date" type="hidden" value="${OpenTHC.POS.Cart.date}">`);
		$('#psi-form').append(`<input name="cart-time" type="hidden" value="${OpenTHC.POS.Cart.time}">`);
		$('#psi-form').append('<input name="name" type="hidden" value="' + $('#customer-name').val() + '">');
		$('#psi-form').append(`<input name="cash_incoming" type="hidden" value="${cash_incoming}">`);
		$('#psi-form').append(`<input name="cash_outgoing" type="hidden" value="${cash_outgoing}">`);
		$('#psi-form').submit();

		return false;

	});

});
