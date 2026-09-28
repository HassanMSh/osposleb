/** Handles restaurant menu tabs, click feedback, payment controls and the change helper dialog. */
document.addEventListener("DOMContentLoaded", () => {
    const register = document.querySelector(".till-restaurant");
    if (!register) {
        return;
    }

    const restaurantMenuCategoryKey = "restaurantMenuCategory";
    const clickSoundArea = register.closest(".restaurant-till-layout") || register;
    let clickAudioContext = null;

    /** Plays the short restaurant till click, and stays silent when the browser cannot play sound. */
    function playClickSound() {
        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) {
                return;
            }

            clickAudioContext ??= new AudioContextClass();
            if (clickAudioContext.state === "suspended") {
                clickAudioContext.resume().catch(() => {});
            }

            const startTime = clickAudioContext.currentTime;
            const oscillator = clickAudioContext.createOscillator();
            const gain = clickAudioContext.createGain();
            oscillator.type = "square";
            oscillator.frequency.setValueAtTime(2000, startTime);
            gain.gain.setValueAtTime(0.25, startTime);
            gain.gain.exponentialRampToValueAtTime(0.001, startTime + 0.015);
            oscillator.connect(gain);
            gain.connect(clickAudioContext.destination);
            oscillator.start(startTime);
            oscillator.stop(startTime + 0.015);
        } catch {}
    }

    /** Selects a restaurant menu tab and shows its matching category. */
    function selectRestaurantCategory(selectedCategory) {
        const categoryTabs = Array.from(register.querySelectorAll("[data-restaurant-category]"));
        const selectedTab = categoryTabs.find((categoryTab) => categoryTab.dataset.restaurantCategory === selectedCategory);
        if (!selectedTab) {
            return false;
        }

        categoryTabs.forEach((categoryTab) => {
            const selected = categoryTab === selectedTab;
            categoryTab.setAttribute("aria-selected", selected ? "true" : "false");
            categoryTab.classList.toggle("active", selected);
        });
        register.querySelectorAll(".restaurant-menu-category").forEach((categoryPanel) => {
            categoryPanel.hidden = categoryPanel.id !== "restaurant-menu-category-" + selectedCategory;
        });

        return true;
    }

    const saleHasLines = register.querySelector('#cart_contents form[id^="cart_"]');
    if (!saleHasLines) {
        try {
            window.sessionStorage.removeItem(restaurantMenuCategoryKey);
        } catch {}
    } else {
        let savedCategory = null;
        try {
            savedCategory = window.sessionStorage.getItem(restaurantMenuCategoryKey);
        } catch {}

        if (savedCategory !== null && !selectRestaurantCategory(savedCategory)) {
            try {
                window.sessionStorage.removeItem(restaurantMenuCategoryKey);
            } catch {}
        }
    }

    const sale = document.querySelector("#overall_sale");
    const saleBody = sale?.querySelector(":scope > .panel-body");
    const paymentDetails = sale?.querySelector("#payment_details");
    const paymentHistory = paymentDetails?.querySelector("table#register");
    if (sale && saleBody && paymentDetails) {
        if (paymentHistory) {
            const saleActions = saleBody.querySelector("#buttons_form");
            if (saleActions) {
                saleBody.insertBefore(paymentHistory, saleActions);
            } else {
                saleBody.append(paymentHistory);
            }
        }
        sale.parentElement.append(paymentDetails);
    }

    /** Moves the change helper into a dialog and adds its button beside Complete. */
    function attachChangeHelperDialog(changeHelper, paymentDetails) {
        const completeButton = paymentDetails?.querySelector("#finish_sale_button, #complete_sale_button");
        const closeLabel = changeHelper?.dataset.closeLabel;
        const titleCell = changeHelper?.querySelector("th");
        const title = titleCell?.textContent.trim();
        if (!changeHelper || !completeButton || !closeLabel || !titleCell || !title) {
            return;
        }

        const openButton = document.createElement("button");
        openButton.type = "button";
        openButton.className = "btn btn-sm btn-default restaurant-change-helper-button";
        openButton.setAttribute("aria-label", title);
        openButton.setAttribute("aria-haspopup", "dialog");
        openButton.setAttribute("aria-controls", "restaurant-change-helper-dialog");
        openButton.title = title;

        const calculatorIcon = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        calculatorIcon.setAttribute("viewBox", "0 0 24 24");
        calculatorIcon.setAttribute("width", "20");
        calculatorIcon.setAttribute("height", "20");
        calculatorIcon.setAttribute("aria-hidden", "true");
        calculatorIcon.setAttribute("focusable", "false");
        const calculatorShape = document.createElementNS("http://www.w3.org/2000/svg", "path");
        calculatorShape.setAttribute("fill", "currentColor");
        calculatorShape.setAttribute("d", "M19 2H5c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 18H5V4h14v16zM7 6h10v4H7zm0 6h3v3H7zm0 4h3v3H7zm4-4h3v3h-3zm0 4h3v3h-3zm4-4h2v3h-2zm0 4h2v3h-2z");
        calculatorIcon.appendChild(calculatorShape);
        openButton.appendChild(calculatorIcon);

        const overlay = document.createElement("div");
        overlay.className = "restaurant-change-helper-overlay";
        overlay.hidden = true;

        const dialog = document.createElement("section");
        dialog.id = "restaurant-change-helper-dialog";
        dialog.className = "restaurant-change-helper-dialog";
        dialog.setAttribute("role", "dialog");
        dialog.setAttribute("aria-modal", "true");
        dialog.setAttribute("aria-labelledby", "restaurant-change-helper-title");
        dialog.dir = window.getComputedStyle(register).direction === "rtl" ? "rtl" : "ltr";

        titleCell.id = "restaurant-change-helper-title";

        const dialogHeader = document.createElement("div");
        dialogHeader.className = "restaurant-change-helper-header";

        const closeButton = document.createElement("button");
        closeButton.type = "button";
        closeButton.className = "btn btn-default restaurant-change-helper-close";
        closeButton.setAttribute("aria-label", closeLabel);
        closeButton.title = closeLabel;
        closeButton.textContent = "×";
        dialogHeader.appendChild(closeButton);

        dialog.append(dialogHeader, changeHelper);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);
        completeButton.insertAdjacentElement("afterend", openButton);

        const amountInput = changeHelper.querySelector("#change_helper_amount");

        /** Hides the dialog and restores focus to its opening button. */
        function closeDialog() {
            overlay.hidden = true;
            openButton.focus();
        }

        openButton.addEventListener("click", () => {
            if (!overlay.hidden) {
                closeDialog();
                return;
            }

            overlay.hidden = false;
            amountInput?.focus();
            amountInput?.select();
        });
        closeButton.addEventListener("click", closeDialog);
        document.addEventListener("click", (event) => {
            if (overlay.hidden || dialog.contains(event.target) || openButton.contains(event.target)) {
                return;
            }

            closeDialog();
        });

        // The register cancels the sale on Escape keyup, so the keyup of an Escape that closed the dialog must not reach it.
        let escapeClosedDialog = false;
        window.addEventListener("keyup", (event) => {
            if (event.key !== "Escape" || !escapeClosedDialog) {
                return;
            }

            escapeClosedDialog = false;
            event.stopPropagation();
        }, true);

        document.addEventListener("keydown", (event) => {
            if (overlay.hidden) {
                return;
            }

            if (event.key === "Escape") {
                event.preventDefault();
                escapeClosedDialog = true;
                closeDialog();
                return;
            }

            if (event.key !== "Tab") {
                return;
            }

            const focusable = Array.from(dialog.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), [href], [tabindex]:not([tabindex="-1"])'));
            const firstFocusable = focusable[0];
            const lastFocusable = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === completeButton) {
                event.preventDefault();
                lastFocusable?.focus();
            } else if (event.shiftKey && (document.activeElement === firstFocusable || !dialog.contains(document.activeElement))) {
                event.preventDefault();
                (completeButton.disabled ? lastFocusable : completeButton)?.focus();
            } else if (!event.shiftKey && document.activeElement === completeButton) {
                event.preventDefault();
                firstFocusable?.focus();
            } else if (!event.shiftKey && (document.activeElement === lastFocusable || !dialog.contains(document.activeElement))) {
                event.preventDefault();
                (completeButton.disabled ? firstFocusable : completeButton)?.focus();
            }
        });
    }

    attachChangeHelperDialog(sale?.querySelector("#change_helper"), paymentDetails);

    document.addEventListener("click", (event) => {
        const tab = event.target.closest("[data-restaurant-category]");
        if (!tab) {
            return;
        }

        if (!register.contains(tab)) {
            return;
        }

        const selectedCategory = tab.dataset.restaurantCategory;
        selectRestaurantCategory(selectedCategory);
        try {
            window.sessionStorage.setItem(restaurantMenuCategoryKey, selectedCategory);
        } catch {}
    });

    let lineStepSaveStarted = false;

    clickSoundArea.addEventListener("pointerdown", (event) => {
        if (register.dataset.clickSound === "1") {
            const clickTarget = event.target.closest("button, a, [role='button'], input[type='button'], input[type='submit'], input[type='reset'], .btn");
            if (clickTarget && clickSoundArea.contains(clickTarget)) {
                playClickSound();
            }
        }

        const stepButton = event.target.closest(".restaurant-line-step");
        if (stepButton && register.contains(stepButton)) {
            event.preventDefault();
        }
    });

    register.addEventListener("click", (event) => {
        const stepButton = event.target.closest(".restaurant-line-step");
        if (stepButton && register.contains(stepButton)) {
            if (lineStepSaveStarted) {
                return;
            }

            const stepTarget = stepButton.dataset.stepTarget;
            if (stepTarget !== "quantity" && stepTarget !== "discount") {
                return;
            }

            const stepInput = stepButton.closest("td")?.querySelector(`input[name="${stepTarget}"]`);
            const cartForm = stepInput?.form;
            if (!stepInput || !cartForm) {
                return;
            }

            lineStepSaveStarted = true;
            stepInput.value = stepButton.dataset.stepValue;
            document.querySelectorAll(".restaurant-line-step").forEach((button) => {
                button.disabled = true;
            });

            cartForm.requestSubmit();
        }
    });

    register.addEventListener("change", (event) => {
        const discountInput = event.target.closest('input[name="discount"]');
        if (discountInput) {
            document.getElementById(discountInput.getAttribute("form"))?.requestSubmit();
        }
    });
});
