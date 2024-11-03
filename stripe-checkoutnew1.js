document.addEventListener('DOMContentLoaded', function () {
    const ageFieldsContainers = document.querySelectorAll('.age-fields-container');
    const form = document.getElementById('checkout-form');

    // Check if there are any age-related fields
    const hasAgeFields = ageFieldsContainers.length > 0;

    function validateAge(age) {
        return age >= 7 && age <= 15;
    }

    function validateAllAges() {
        let isValid = true;
        ageFieldsContainers.forEach(function(container) {
            container.querySelectorAll('input[name^="age["]').forEach(function(input) {
                const age = parseInt(input.value);
                const validationSpan = input.nextElementSibling;
                if (!validateAge(age)) {
                    isValid = false;
                    validationSpan.textContent = 'Age must be between 7 and 15';
                    validationSpan.style.color = 'red';
                    input.setCustomValidity('Age must be between 7 and 15');
                } else {
                    validationSpan.textContent = '✓';
                    validationSpan.style.color = 'green';
                    input.setCustomValidity('');
                }
            });
        });
        return isValid;
    }

    if (hasAgeFields) {
        ageFieldsContainers.forEach(function(container) {
            container.addEventListener('input', function(e) {
                if (e.target.name.startsWith('age[')) {
                    validateAllAges();
                }
            });
        });
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            if (hasAgeFields && !validateAllAges()) {
                alert('Please correct the age entries. All ages must be between 7 and 15.');
                return;
            }

            const formData = new FormData(form);

            fetch(stripe_checkout_ajax.ajax_url, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.text();
            })
            .then(text => {
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('Error parsing JSON:', e);
                    console.log('Raw response:', text);
                    throw new Error('Invalid JSON response');
                }
                console.log('Response:', data);
                if (data.success && data.data && data.data.session_url) {
                    console.log('Redirecting to:', data.data.session_url);
                    window.location.href = data.data.session_url;
                } else {
                    console.error('Error creating Stripe session', data);
                    alert('Error creating Stripe session. Please check the console for more details.');
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                alert('An error occurred. Please check the console for more details.');
            });
        });
    }

    // Initial validation of ages
    if (hasAgeFields) {
        validateAllAges();
    }
});