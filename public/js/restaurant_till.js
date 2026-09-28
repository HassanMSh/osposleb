/** Separates payment controls while keeping payment history in the bill's original order. */
document.addEventListener("DOMContentLoaded", () => {
    const register = document.querySelector(".till-restaurant");
    if (!register) {
        return;
    }

    const restaurantMenuCategoryKey = "restaurantMenuCategory";

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

    register.addEventListener("pointerdown", (event) => {
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
