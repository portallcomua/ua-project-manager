/* UA Pay Box — Freemius Checkout only. */
(function () {
	'use strict';
	var D = window.UPB || {};
	var modal, els = {};

	function q(selector, root) { return (root || document).querySelector(selector); }

	function showMessage(message, info) {
		els.msg.textContent = message;
		els.msg.className = 'upb-modal__msg' + (info ? ' is-info' : '');
		els.msg.hidden = false;
		els.body.hidden = true;
	}

	function openModal(id) {
		if (!D.loggedIn) {
			window.location.href = D.loginUrl;
			return;
		}
		var service = D.services && D.services[id];
		if (!service || !modal) {
			window.alert('Послугу не знайдено.');
			return;
		}
		els.svc.textContent = service.name || '';
		els.price.textContent = service.price || '';
		els.msg.hidden = true;
		els.body.hidden = false;
		els.checkout.hidden = !service.checkoutUrl;
		els.checkout.style.display = service.checkoutUrl ? '' : 'none';
		if (service.checkoutUrl) {
			els.checkout.href = service.checkoutUrl;
		}
		if (['active', 'unlimited', 'expiring'].indexOf(service.state) !== -1) {
			showMessage('Ліцензія Freemius уже активна.', true);
		} else if (!service.checkoutUrl) {
			showMessage('Для цієї послуги ще не налаштовано Freemius Checkout. Зверніться до адміністратора.');
		}
		modal.hidden = false;
		document.body.classList.add('upb-lock');
	}

	function closeModal() {
		if (!modal || modal.hidden) { return; }
		modal.hidden = true;
		document.body.classList.remove('upb-lock');
	}

	function init() {
		modal = document.getElementById('upb-modal');
		if (!modal) { return; }
		els.svc = q('[data-upb-svc]', modal);
		els.price = q('[data-upb-price]', modal);
		els.msg = q('[data-upb-msg]', modal);
		els.body = q('[data-upb-body]', modal);
		els.checkout = q('[data-upb-freemius]', modal);
		document.addEventListener('click', function (event) {
			var buy = event.target.closest ? event.target.closest('[data-upb-buy]') : null;
			if (buy) {
				event.preventDefault();
				openModal(buy.getAttribute('data-upb-buy'));
				return;
			}
			if (event.target.closest && event.target.closest('[data-upb-close]')) {
				closeModal();
			}
		});
		document.addEventListener('keydown', function (event) {
			if ('Escape' === event.key) { closeModal(); }
		});
	}
	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
	window.UPB = window.UPB || {};
	window.UPB.open = openModal;
})();
