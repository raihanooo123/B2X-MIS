/** Clear uncontrolled password inputs after a request, including silent autofill. */
export function clearPasswords(form: HTMLFormElement) {
    for (const name of ['password', 'password_confirmation']) {
        const input = form.elements.namedItem(name);
        if (input instanceof HTMLInputElement) input.value = '';
    }
}
