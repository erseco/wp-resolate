(function () {
    'use strict';

    const config = window.resolateExportWorkspaceConfig || {};
    const readyEvent = (config.events && config.events.ready) || 'resolateZeta:ready';
    const errorEvent = (config.events && config.events.error) || 'resolateZeta:error';
    const frameTarget = config.frameTarget || 'resolateExportFrame';
    const strings = config.strings || {};

    const statusElement = document.querySelector('[data-resolate-workspace-status]');
    const steps = Array.from(document.querySelectorAll('[data-resolate-step]'));

    const frame = (function getFrame() {
        const frames = document.getElementsByName(frameTarget);
        if (frames && frames.length) {
            return frames[0];
        }
        return null;
    })();

    function setStatus(message, stateClass) {
        if (!statusElement) {
            return;
        }
        statusElement.textContent = message || '';
        statusElement.dataset.state = stateClass || '';
    }

    function setStepState(stepKey, state, message) {
        const element = steps.find((item) => item.dataset.resolateStep === stepKey);
        if (!element) {
            return;
        }
        element.classList.remove('is-pending', 'is-active', 'is-ready', 'is-done', 'is-error');
        if (state) {
            element.classList.add('is-' + state);
        }
        if (typeof message === 'string') {
            const status = element.querySelector('[data-resolate-step-status]');
            if (status) {
                status.textContent = message;
            }
        }
    }

    function markInitialStates() {
        setStepState('loader', 'active', strings.loaderLoading || '');
        steps.forEach((element) => {
            const key = element.dataset.resolateStep;
            if (!key || key === 'loader') {
                return;
            }
            const available = element.dataset.resolateStepAvailable === '1';
            if (available) {
                setStepState(key, 'pending', strings.stepPending || '');
            }
        });
    }

    function enableButtons() {
        document.querySelectorAll('[data-resolate-step-target]').forEach((button) => {
            button.classList.remove('disabled');
            button.removeAttribute('aria-disabled');
        });
    }

    function handleReady() {
        setStatus(strings.loaderReady || '');
        setStepState('loader', 'done', strings.loaderReady || '');
        steps.forEach((element) => {
            const key = element.dataset.resolateStep;
            if (!key || key === 'loader') {
                return;
            }
            if (element.dataset.resolateStepAvailable !== '1') {
                return;
            }
            setStepState(key, 'ready', strings.stepReady || '');
        });
        enableButtons();
    }

    function handleError(event) {
        const message = strings.loaderError || '';
        setStatus(message, 'error');
        setStepState('loader', 'error', message);
        if (event && event.detail && event.detail.error) {
            // eslint-disable-next-line no-console
            console.error('Resolate ZetaJS', event.detail.error);
        }
    }

    function handleActionClick(event) {
        const target = event.currentTarget;
        if (target.classList.contains('disabled') || target.getAttribute('aria-disabled') === 'true') {
            event.preventDefault();
            return;
        }
        const step = target.dataset.resolateStepTarget;
        if (!step) {
            return;
        }
        setStepState(step, 'active', strings.stepWorking || '');
        if (frame) {
            frame.dataset.resolateActiveStep = step;
        }
    }

    function handleFrameLoad() {
        if (!frame) {
            return;
        }
        const activeStep = frame.dataset.resolateActiveStep;
        if (!activeStep) {
            return;
        }
        setStepState(activeStep, 'done', strings.stepDone || '');
        frame.dataset.resolateActiveStep = '';
    }

    function bindEvents() {
        document.querySelectorAll('[data-resolate-step-target]').forEach((button) => {
            button.addEventListener('click', handleActionClick);
        });
        if (frame) {
            frame.addEventListener('load', handleFrameLoad);
        }
        window.addEventListener(readyEvent, handleReady, { once: true });
        window.addEventListener(errorEvent, handleError, { once: true });
    }

    document.addEventListener('DOMContentLoaded', () => {
        markInitialStates();
        setStatus(strings.loaderLoading || '');
        bindEvents();
    });
})();
