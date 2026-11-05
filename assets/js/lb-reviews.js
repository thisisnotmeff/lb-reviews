/* LB Reviews – "Read more", "Show more" and slider. No dependencies. */
(function () {
	'use strict';

	// The WPBakery / Uncode front-end editor re-adds this script every time an element is saved:
	// run the setup only once, and let later copies just pick up the new elements.
	if (window.LBReviews && typeof window.LBReviews.init === 'function') {
		window.LBReviews.init();
		return;
	}

	var i18n = { more: 'Read more', less: 'Read less' };

	// Show "Read more" only when the clamped text overflows. Re-run whenever the width changes.
	// Batched (all writes, then all reads, then all writes) so the page lays out once, not once per card.
	function measureAll(cards) {
		var items = [];
		Array.prototype.forEach.call(cards, function (card) {
			var text = card.querySelector('.lbr-card__text');
			var btn = card.querySelector('.lbr-card__more');
			if (!text || !btn || !text.dataset.lbrReady || btn.getAttribute('aria-expanded') === 'true') {
				return; // Not set up yet, or open: leave it alone.
			}
			items.push({ card: card, text: text, btn: btn });
		});
		items.forEach(function (it) { it.text.classList.add('is-clamped'); });
		items.forEach(function (it) {
			it.visible = it.card.offsetParent !== null;
			it.overflows = it.visible && it.text.scrollHeight - it.text.clientHeight > 2;
		});
		items.forEach(function (it) {
			if (!it.visible) {
				return;
			}
			if (it.btn.hidden !== !it.overflows) {
				it.btn.hidden = !it.overflows;
			}
			if (!it.overflows) {
				it.text.classList.remove('is-clamped');
			}
		});
	}


	function setupClamp(card) {
		var text = card.querySelector('.lbr-card__text');
		var btn = card.querySelector('.lbr-card__more');
		if (!text || !btn) {
			return;
		}
		if (!text.dataset.lbrReady) {
			text.dataset.lbrReady = '1';
			var labelMore = btn.textContent || i18n.more;
			var labelLess = btn.getAttribute('data-less') || i18n.less;
			btn.addEventListener('click', function () {
				var open = btn.getAttribute('aria-expanded') === 'true';
				text.classList.toggle('is-clamped', open);
				btn.setAttribute('aria-expanded', open ? 'false' : 'true');
				btn.textContent = open ? labelMore : labelLess;
			});
		}
	}

	// Expired or blocked avatar photo → coloured initial.
	function fallbackAvatar(img) {
		if (!img.parentNode) {
			return;
		}
		var span = document.createElement('span');
		span.className = 'lbr-card__avatar lbr-card__avatar--initial';
		span.setAttribute('aria-hidden', 'true');
		span.style.setProperty('--lbr-hue', img.getAttribute('data-hue') || '30');
		span.textContent = img.getAttribute('data-initial') || '•';
		img.parentNode.replaceChild(span, img);
	}

	function setupAvatars(section) {
		section.querySelectorAll('img.lbr-card__avatar[data-initial]').forEach(function (img) {
			if (img.complete && img.naturalWidth === 0 && img.currentSrc) {
				fallbackAvatar(img);
			} else {
				img.addEventListener('error', function () { fallbackAvatar(img); }, { once: true });
			}
		});
	}

	function setupSection(section) {
		if (section.getAttribute('data-lbr-init')) {
			return;
		}
		section.setAttribute('data-lbr-init', '1');
		setupAvatars(section);
		var cards = section.querySelectorAll('.lbr-card');
		cards.forEach(setupClamp);
		measureAll(cards);

		// Column width changes (resize, rotation, Uncode layout) → re-measure.
		if ('ResizeObserver' in window) {
			var lastWidth = section.clientWidth;
			var pending = null;
			new ResizeObserver(function () {
				if (section.clientWidth === lastWidth) {
					return;
				}
				lastWidth = section.clientWidth;
				cancelAnimationFrame(pending);
				pending = requestAnimationFrame(function () {
					measureAll(section.querySelectorAll('.lbr-card'));
				});
			}).observe(section);
		}

		var perPage = parseInt(section.getAttribute('data-per-page'), 10) || 0;
		var loadBtn = section.querySelector('.lbr__loadmore');
		if (!loadBtn || !perPage) {
			return;
		}

		loadBtn.addEventListener('click', function () {
			var hidden = section.querySelectorAll('.lbr-card--more');
			for (var i = 0; i < hidden.length && i < perPage; i++) {
				hidden[i].classList.remove('lbr-card--more');
				hidden[i].classList.add('is-revealed');
				setupClamp(hidden[i]);
			}
			measureAll(Array.prototype.slice.call(hidden, 0, i));
			if (i < perPage || !section.querySelector('.lbr-card--more')) {
				loadBtn.parentNode.removeChild(loadBtn);
			}
			if (hidden[0]) {
				var link = hidden[0].querySelector('a, button');
				if (link) {
					link.focus({ preventScroll: true });
				}
			}
		});
	}

	/* ---------- Slider ---------- */

	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var inEditor = /[?&]vc_editable=true/.test(window.location.search) || document.documentElement.classList.contains('vc_editor') || !!(document.body && document.body.classList.contains('compose-mode'));

	function setupSlider(section) {
		var track = section.querySelector('.lbr__grid');
		if (!track || section.getAttribute('data-lbr-slider')) {
			return;
		}
		section.setAttribute('data-lbr-slider', '1');
		var cards = Array.prototype.slice.call(track.querySelectorAll('.lbr-card'));
		var prev = section.querySelector('.lbr__arrow--prev');
		var next = section.querySelector('.lbr__arrow--next');
		var dotsWrap = section.querySelector('.lbr__dots');
		var nav = section.querySelector('.lbr__nav');
		var pauseBtn = section.querySelector('.lbr__pause');
		var targets = [];
		var current = 0;
		var dots = [];

		// One "page" = as many cards as fit in the view; the last page is aligned to the end.
		function computeTargets() {
			var max = track.scrollWidth - track.clientWidth;
			var trackLeft = track.getBoundingClientRect().left + parseFloat(getComputedStyle(track).paddingLeft || 0);
			var first = cards[0] ? cards[0].getBoundingClientRect() : null;
			var gap = parseFloat(getComputedStyle(track).columnGap || getComputedStyle(track).gap) || 0;
			var view = first ? Math.max(1, Math.round((track.clientWidth + gap) / (first.width + gap))) : 1;
			targets = [];
			for (var i = 0; i < cards.length; i += view) {
				var pos = track.scrollLeft + cards[i].getBoundingClientRect().left - trackLeft;
				pos = Math.min(Math.max(0, Math.round(pos)), max);
				if (!targets.length || pos > targets[targets.length - 1] + 1) {
					targets.push(pos);
				}
			}
			if (max > 0 && targets[targets.length - 1] < max - 1) {
				targets.push(max);
			}
			if (!targets.length) {
				targets = [0];
			}
		}

		var counter = null;

		function buildDots() {
			if (!dotsWrap) {
				return;
			}
			dotsWrap.innerHTML = '';
			dots = [];
			counter = null;
			// Too many pages for dots (e.g. one review per view on a phone) → "3 / 27".
			if (targets.length > 10) {
				counter = document.createElement('span');
				counter.className = 'lbr__counter';
				dotsWrap.appendChild(counter);
				return;
			}
			dots = targets.map(function (t, i) {
				var b = document.createElement('button');
				b.type = 'button';
				b.className = 'lbr__dot';
				b.setAttribute('aria-label', (i + 1) + ' / ' + targets.length);
				b.addEventListener('click', function () { go(i); });
				dotsWrap.appendChild(b);
				return b;
			});
		}

		function nearest() {
			var x = track.scrollLeft;
			var best = 0;
			for (var i = 1; i < targets.length; i++) {
				if (Math.abs(targets[i] - x) < Math.abs(targets[best] - x)) {
					best = i;
				}
			}
			return best;
		}

		function updateUI() {
			current = nearest();
			var max = track.scrollWidth - track.clientWidth;
			if (prev) {
				prev.disabled = track.scrollLeft <= targets[0] + 2;
			}
			if (next) {
				next.disabled = track.scrollLeft >= max - 2;
			}
			if (counter) {
				counter.textContent = (current + 1) + ' / ' + targets.length;
			}
			dots.forEach(function (d, i) {
				if (i === current) {
					d.setAttribute('aria-current', 'true');
				} else {
					d.removeAttribute('aria-current');
				}
			});
			if (nav) {
				nav.hidden = targets.length <= 1;
			}
		}

		function go(i) {
			i = Math.max(0, Math.min(targets.length - 1, i));
			track.scrollTo({ left: targets[i], behavior: reduceMotion ? 'auto' : 'smooth' });
			current = i;
		}

		function refresh() {
			computeTargets();
			buildDots();
			updateUI();
		}

		if (prev) {
			prev.addEventListener('click', function () { go(nearest() - 1); });
		}
		if (next) {
			next.addEventListener('click', function () { go(nearest() + 1); });
		}

		var ticking = false;
		track.addEventListener('scroll', function () {
			if (!ticking) {
				ticking = true;
				requestAnimationFrame(function () {
					ticking = false;
					updateUI();
				});
			}
		}, { passive: true });

		track.addEventListener('keydown', function (e) {
			if (e.target !== track) {
				return;
			}
			if (e.key === 'ArrowRight') {
				e.preventDefault();
				go(nearest() + 1);
			} else if (e.key === 'ArrowLeft') {
				e.preventDefault();
				go(nearest() - 1);
			}
		});

		// Mouse drag (touch already scrolls natively).
		var drag = null;
		track.addEventListener('pointerdown', function (e) {
			if (e.pointerType !== 'mouse' || e.button !== 0 || e.target.closest('a, button')) {
				return;
			}
			drag = { x: e.clientX, left: track.scrollLeft, moved: false };
		});
		window.addEventListener('pointermove', function (e) {
			if (!drag) {
				return;
			}
			var dx = e.clientX - drag.x;
			if (!drag.moved && Math.abs(dx) > 5) {
				drag.moved = true;
				section.classList.add('is-dragging');
			}
			if (drag.moved) {
				track.scrollLeft = drag.left - dx;
			}
		});
		window.addEventListener('pointerup', function () {
			if (!drag) {
				return;
			}
			var moved = drag.moved;
			var startLeft = drag.left;
			drag = null;
			section.classList.remove('is-dragging');
			if (moved) {
				var i = nearest();
				// A short drag still moves one page in its direction.
				if (targets[i] === startLeft && track.scrollLeft !== startLeft) {
					i += track.scrollLeft > startLeft ? 1 : -1;
				}
				go(i);
			}
		});

		// Autoplay: loops, pauses on hover / focus / hidden tab, never with reduced motion.
		var seconds = parseInt(section.getAttribute('data-autoplay'), 10) || 0;
		// No autoplay inside the page builder: it would keep moving while you edit.
		if (seconds > 0 && !reduceMotion && !inEditor) {
			var hover = false;
			var focus = false;
			var userPaused = false;
			var timer = setInterval(function () {
				if (!section.isConnected) {
					clearInterval(timer); // Element removed or re-rendered.
					return;
				}
				if (hover || focus || userPaused || document.hidden || targets.length <= 1) {
					return;
				}
				var n = nearest();
				go(n >= targets.length - 1 ? 0 : n + 1);
			}, Math.max(2, seconds) * 1000);
			section.addEventListener('mouseenter', function () { hover = true; });
			section.addEventListener('mouseleave', function () { hover = false; });
			section.addEventListener('focusin', function (e) { focus = e.target !== pauseBtn; });
			section.addEventListener('focusout', function () { focus = false; });
			if (pauseBtn) {
				pauseBtn.addEventListener('click', function () {
					userPaused = !userPaused;
					pauseBtn.setAttribute('aria-pressed', userPaused ? 'true' : 'false');
					pauseBtn.textContent = userPaused ? pauseBtn.getAttribute('data-label-play') : pauseBtn.getAttribute('data-label-pause');
				});
			}
		} else if (pauseBtn) {
			pauseBtn.parentNode.removeChild(pauseBtn);
		}

		refresh();
		if ('ResizeObserver' in window) {
			var w = track.clientWidth;
			new ResizeObserver(function () {
				if (track.clientWidth !== w) {
					w = track.clientWidth;
					refresh();
				}
			}).observe(track);
		}
		window.addEventListener('load', refresh);
	}

	function init() {
		document.querySelectorAll('.lbr').forEach(setupSection);
		document.querySelectorAll('.lbr--slider').forEach(setupSlider);
	}

	window.LBReviews = { init: init };

	// Page builder: elements are re-rendered in place after each save → set up the new ones.
	if (inEditor && 'MutationObserver' in window) {
		var queued = null;
		new MutationObserver(function () {
			if (queued) {
				return;
			}
			queued = setTimeout(function () {
				queued = null;
				init();
			}, 300);
		}).observe(document.documentElement, { childList: true, subtree: true });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	// Re-check clamping once fonts and Uncode's layout have settled; pick up late elements.
	window.addEventListener('load', function () {
		init();
		measureAll(document.querySelectorAll('.lbr-card'));
	});
})();
