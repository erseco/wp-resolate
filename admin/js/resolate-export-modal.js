(function ($) {
    'use strict';

    const config = window.resolateExportModalConfig || {};
    const selectors = {
        modal: '#resolate-export-modal',
        open: '[data-resolate-export-modal-open]',
        close: '[data-resolate-export-modal-close]',
        step: '[data-resolate-step]'
    };
    const bodyOpenClass = 'resolate-export-modal--open';
    const readyEvent = (config.events && config.events.ready) || 'resolateZeta:ready';
    const errorEvent = (config.events && config.events.error) || 'resolateZeta:error';
    const frameTarget = config.frameTarget || 'resolateExportFrame';
    const strings = config.strings || {};

    const state = {
        loaderPromise: null,
        loaderLoaded: false,
        lastTrigger: null
    };

    function getModal() {
        return $(selectors.modal);
    }

    function normalizeAvailable(value) {
        if (typeof value === 'string') {
            return value === '1';
        }
        return Boolean(value);
    }

    function setStepState(step, stateName, message) {
        const $modal = getModal();
        const $step = $modal.find(selectors.step + '[data-resolate-step="' + step + '"]');
        if (!$step.length) {
            return;
        }
        const states = 'is-pending is-active is-ready is-done is-error';
        $step.removeClass(states);
        if (stateName) {
            $step.addClass('is-' + stateName);
        }
        if (typeof message === 'string') {
            const $status = $step.find('[data-resolate-step-status]');
            if ($status.length) {
                $status.text(message);
            }
        }
    }

    function resetSteps() {
        const $modal = getModal();
        setStepState('loader', 'active', strings.loaderLoading || '');
        $modal.find(selectors.step).each(function () {
            const $item = $(this);
            const key = $item.data('resolate-step');
            if (key === 'loader') {
                return;
            }
            const available = normalizeAvailable($item.data('resolate-step-available'));
            if (available) {
                setStepState(key, 'pending', strings.stepPending || '');
            } else {
                $item.addClass('is-disabled');
            }
        });
    }

    function markStepsReady() {
        const $modal = getModal();
        $modal.find(selectors.step).each(function () {
            const $item = $(this);
            const key = $item.data('resolate-step');
            if (key === 'loader') {
                return;
            }
            if (!normalizeAvailable($item.data('resolate-step-available'))) {
                return;
            }
            setStepState(key, 'ready', strings.stepReady || '');
            const $button = $modal.find('[data-resolate-step-target="' + key + '"]');
            if ($button.length) {
                $button.removeClass('disabled').removeAttr('aria-disabled');
            }
        });
    }

    function ensureLoader() {
        if (state.loaderLoaded || (window.resolateZeta && window.resolateZeta.ready)) {
            state.loaderLoaded = true;
            return Promise.resolve();
        }
        if (state.loaderPromise) {
            return state.loaderPromise;
        }
        state.loaderPromise = new Promise(function (resolve, reject) {
            const onReady = function () {
                window.removeEventListener(readyEvent, onReady);
                window.removeEventListener(errorEvent, onError);
                state.loaderLoaded = true;
                state.loaderPromise = null;
                resolve();
            };
            const onError = function (event) {
                window.removeEventListener(readyEvent, onReady);
                window.removeEventListener(errorEvent, onError);
                state.loaderPromise = null;
                reject(event);
            };
            window.addEventListener(readyEvent, onReady, { once: true });
            window.addEventListener(errorEvent, onError, { once: true });

            if (config.loaderConfig) {
                window.resolateZetaLoaderConfig = $.extend(true, {}, config.loaderConfig, window.resolateZetaLoaderConfig || {});
            }

            if (window.resolateZeta && window.resolateZeta.ready) {
                onReady();
                return;
            }

            const existing = document.querySelector('script[data-resolate-zetajs-loader="1"]');
            if (existing) {
                return;
            }

            const scriptUrl = config.loaderUrl;
            if (!scriptUrl) {
                onError(new Error('Missing loader URL'));
                return;
            }

            const script = document.createElement('script');
            script.type = 'module';
            script.src = scriptUrl;
            script.dataset.resolateZetajsLoader = '1';
            script.addEventListener('error', onError);
            document.head.appendChild(script);
        });

        return state.loaderPromise;
    }

    function getDownloadFrame() {
        const frames = document.getElementsByName(frameTarget);
        if (frames && frames.length) {
            return frames[0];
        }
        return null;
    }

    function attachFrameListener($modal) {
        const frame = getDownloadFrame();
        if (!frame) {
            return;
        }
        frame.addEventListener('load', function () {
            const activeStep = frame.dataset.resolateActiveStep;
            if (!activeStep) {
                return;
            }
            setStepState(activeStep, 'done', strings.stepDone || '');
            frame.dataset.resolateActiveStep = '';
        });
    }

    function showModal(trigger) {
        const $modal = getModal();
        if (!$modal.length) {
            return;
        }
        state.lastTrigger = trigger || null;
        $modal.removeAttr('hidden');
        $('body').addClass(bodyOpenClass);
        resetSteps();
        ensureLoader()
            .then(function () {
                setStepState('loader', 'done', strings.loaderReady || '');
                markStepsReady();
            })
            .catch(function (error) {
                setStepState('loader', 'error', strings.loaderError || '');
                window.console.error('Resolate ZetaJS', error);
            });
        const $close = $modal.find(selectors.close).first();
        if ($close.length) {
            setTimeout(function () {
                $close.trigger('focus');
            }, 0);
        }
    }

    function closeModal() {
        const $modal = getModal();
        if (!$modal.length || $modal.is('[hidden]')) {
            return;
        }
        $modal.attr('hidden', 'hidden');
        $('body').removeClass(bodyOpenClass);
        if (state.lastTrigger && typeof state.lastTrigger.focus === 'function') {
            try {
                state.lastTrigger.focus();
            } catch (e) {
                // Ignore focus errors.
            }
        }
        state.lastTrigger = null;
    }

    function handleActionClick(event) {
        const $button = $(this);
        if ($button.is('[aria-disabled="true"]') || $button.hasClass('disabled')) {
            event.preventDefault();
            return;
        }
        const step = $button.data('resolate-step-target');
        if (!step) {
            return;
        }
        setStepState(step, 'active', strings.stepWorking || '');
        const frame = getDownloadFrame();
        if (frame) {
            frame.dataset.resolateActiveStep = step;
        }
    }

    function bindEvents() {
        const $modal = getModal();
        if (!$modal.length) {
            return;
        }

        attachFrameListener($modal);

        $(document).on('click', selectors.open, function (event) {
            event.preventDefault();
            showModal(this);
        });

        $modal.on('click', selectors.close, function (event) {
            event.preventDefault();
            closeModal();
        });

        $modal.on('click', '.resolate-export-modal__overlay', function (event) {
            if (event.target === this) {
                closeModal();
            }
        });

        $modal.on('click', '[data-resolate-step-target]', handleActionClick);

        $(document).on('keydown', function (event) {
            if ('Escape' === event.key && !getModal().is('[hidden]')) {
                event.preventDefault();
                closeModal();
            }
        });
    }

    $(function () {
        bindEvents();
    });
})(jQuery);
