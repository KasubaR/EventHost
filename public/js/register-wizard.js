(function () {
    var form = document.getElementById('register-form');
    if (!form) {
        return;
    }

    var totalSteps = 3;
    var currentStep = parseInt(form.dataset.initialStep || '1', 10);
    if (currentStep < 1 || currentStep > totalSteps) {
        currentStep = 1;
    }

    var panels = form.querySelectorAll('.auth-step-panel');
    var stepItems = document.querySelectorAll('.auth-wizard-step');
    var backBtn = form.querySelector('.auth-step-back');
    var nextBtn = form.querySelector('.auth-step-next');
    var submitBtn = form.querySelector('.auth-step-submit');
    var stepHint = form.querySelector('.auth-step-hint');

    var hints = {
        1: 'Step 1 of 3 — takes about 30 seconds',
        2: 'Step 2 of 3 — pick a password you will remember',
        3: 'Step 3 of 3 — optional details, then you are in',
    };

    function showFieldError(input, message) {
        var field = input.closest('.auth-field');
        if (!field) {
            return;
        }

        var existing = field.querySelector('.auth-step-error');
        if (existing) {
            existing.remove();
        }

        input.classList.add('auth-input--error');

        var span = document.createElement('span');
        span.className = 'auth-error auth-step-error';
        span.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + message;
        field.appendChild(span);
    }

    function clearStepErrors(panel) {
        panel.querySelectorAll('.auth-step-error').forEach(function (el) {
            el.remove();
        });
        panel.querySelectorAll('.auth-input--error').forEach(function (el) {
            el.classList.remove('auth-input--error');
        });
    }

    function validateStep(step) {
        var panel = form.querySelector('.auth-step-panel[data-step="' + step + '"]');
        if (!panel) {
            return true;
        }

        clearStepErrors(panel);

        if (step === 1) {
            var name = form.querySelector('#name');
            var email = form.querySelector('#email');

            if (!name.value.trim()) {
                showFieldError(name, 'Please enter your name.');
                name.focus();
                return false;
            }

            if (!email.value.trim()) {
                showFieldError(email, 'Please enter your email address.');
                email.focus();
                return false;
            }

            if (!email.validity.valid) {
                showFieldError(email, 'Please enter a valid email address.');
                email.focus();
                return false;
            }
        }

        if (step === 2) {
            var password = form.querySelector('#password');
            var confirm = form.querySelector('#password_confirmation');

            if (password.value.length < 8) {
                showFieldError(password, 'Password must be at least 8 characters.');
                password.focus();
                return false;
            }

            if (password.value !== confirm.value) {
                showFieldError(confirm, 'Passwords do not match.');
                confirm.focus();
                return false;
            }
        }

        return true;
    }

    function goToStep(step) {
        if (step < 1 || step > totalSteps) {
            return;
        }

        currentStep = step;

        panels.forEach(function (panel) {
            var panelStep = parseInt(panel.dataset.step, 10);
            panel.hidden = panelStep !== currentStep;
        });

        stepItems.forEach(function (item) {
            var itemStep = parseInt(item.dataset.step, 10);
            item.classList.toggle('is-active', itemStep === currentStep);
            item.classList.toggle('is-complete', itemStep < currentStep);
        });

        if (backBtn) {
            backBtn.hidden = currentStep === 1;
        }

        if (nextBtn) {
            nextBtn.hidden = currentStep === totalSteps;
        }

        if (submitBtn) {
            submitBtn.hidden = currentStep !== totalSteps;
        }

        if (stepHint && hints[currentStep]) {
            stepHint.textContent = hints[currentStep];
        }

        var firstInput = form.querySelector('.auth-step-panel[data-step="' + currentStep + '"] input:not([type="hidden"])');
        if (firstInput && document.activeElement !== firstInput) {
            firstInput.focus({ preventScroll: true });
        }
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', function () {
            if (validateStep(currentStep)) {
                goToStep(currentStep + 1);
            }
        });
    }

    if (backBtn) {
        backBtn.addEventListener('click', function () {
            goToStep(currentStep - 1);
        });
    }

    form.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && currentStep < totalSteps) {
            var tag = event.target.tagName.toLowerCase();
            if (tag === 'input' && event.target.type !== 'submit') {
                event.preventDefault();
                nextBtn.click();
            }
        }
    });

    goToStep(currentStep);
}());
