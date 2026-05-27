import { escape } from "lodash";
import { MusicAdminSection } from './AdminSection';

declare var OCP : any;
declare function t(app : string, text : string, vars?: Object) : string;

class ScrobblerAdmin implements MusicAdminSection {

	#identifier: string;
	#name: string;
	#api_key: string;
	#api_secret: string;

	constructor(data: any) {
		this.#identifier = data.identifier;
		this.#name = data.name;
		this.#api_key = data.api_key;
		this.#api_secret = data.api_secret;
	}

	mount(element: HTMLElement) {
		const containerEl = document.createElement('div');
		containerEl.classList.add('settings-section');
		const formEl = document.createElement('form');
		formEl.classList.add('scrobbler', this.#identifier);
		containerEl.insertAdjacentElement('afterbegin', formEl);
        const keyLabel = escape(t('music', 'API Key'));
        const secretLabel = escape(t('music', 'API Secret'));
		const serviceLabel = escape(this.#name);
		formEl.insertAdjacentHTML('afterbegin', `
		<fieldset>
			<legend><h3>${serviceLabel}</h3></legend>
			<div class="field">
				<label for="${escape(this.#identifier)}_api_key">${keyLabel}</label>
				<input name="api_key" placeholder="${keyLabel}" aria-label="${serviceLabel} ${keyLabel}" id="${escape(this.#identifier)}_api_key" type="text" value="${escape(this.#api_key)}" data-original-value="${escape(this.#api_key)}"/>
			</div>
			<div class="field">
				<label for="${escape(this.#identifier)}_api_secret">${secretLabel}</label>
				<input name="api_secret" placeholder="${secretLabel}" aria-label="${serviceLabel} ${secretLabel}" id="${escape(this.#identifier)}_api_secret" type="password" value="${escape(this.#api_secret)}" data-original-value="${escape(this.#api_secret)}"/>
			</div>
			<div class="field">
				<button type="submit" title="${escape(t('music', 'Update API credentials for {service}', { service: this.#name }))}">Save</button>
			</div>
		</fieldset>
`);
		element.appendChild(containerEl);
		this.#attachListener(formEl);
	}

	#attachListener(formEl: HTMLFormElement) {
		const apiKeyEl = <HTMLInputElement> formEl.elements.namedItem('api_key');
		const apiSecretEl = <HTMLInputElement> formEl.elements.namedItem('api_secret');

		formEl.addEventListener('submit', function (e: SubmitEvent) {
			e.preventDefault();

			[...formEl.querySelectorAll('input:invalid')].map(removeErrorState);
			// Update the api key, then update the api secret if the key update succeeded.
			OCP.AppConfig.setValue('music', apiKeyEl.id, apiKeyEl.value, {
				success: () => {
					OCP.AppConfig.setValue('music', apiSecretEl.id, apiSecretEl.value, {
						error: (err: any) => {
							setErrorState(apiSecretEl, parseErr(err));
						}
					});
				},
				error: (err: any) => {
					setErrorState(apiKeyEl, parseErr(err));
				}
			});
		});

		// reset validation state upon receiving new input
		formEl.addEventListener('input', (e: InputEvent) => removeErrorState((<HTMLInputElement> e.target)));
	}
}

function setErrorState(el: HTMLInputElement, message: string): void {
	el.setCustomValidity(message);
	el.reportValidity();
}

function removeErrorState(el: HTMLInputElement): void {
	el.setCustomValidity('');
}

/**
 * Parse error from OCP.AppConfig.setValue
 *
 * Older versions of NextCloud use jQuery, which returns an XML document.
 * Depending on the nature of the error, the relevant message may not be present
 * on the data node. In that case, use a generic message.
 * Newer versions use Axios, which returns a plain object
 */
function parseErr(err: {responseXML: XMLDocument}|{message: string}): string {
	if ('responseXML' in err) {
		return err.responseXML.querySelector('data message')?.textContent || t('music', 'Unknown error, please refer to the Nextcloud log');
	}
	return err.message;
}

export default class ScrobblersAdmin implements MusicAdminSection {
	#scrobblers: Array<ScrobblerAdmin>;
	constructor() {
		const scrobblers: Array<any> = OCP.InitialState.loadState('music', 'scrobblers', []);
		this.#scrobblers = scrobblers.map(
			scrobbler => new ScrobblerAdmin(scrobbler)
		);
	}

	mount(element: HTMLElement) {
		const root = document.createElement('div');
		root.classList.add('scrobblers');
		root.insertAdjacentHTML('afterbegin', `
		<div class="scrobbler-intro">
			<h2>${t('music', 'Scrobbler Configuration')}</h2>
			<p>${t('music', 'Configure API connection to begin scrobbling. Check the <a target="_blank" href="https://github.com/nc-music/music/wiki/">documentation</a> for more details.')}</p>
		</div>
		`);
		// DOMPurify removes the target attribute
		// t() doesn't give us access to send args to DOMPurify, so we have to add it later
		root.querySelector('a').target = '_blank';
		this.#scrobblers.forEach(scrobbler => scrobbler.mount(root));
		element.appendChild(root);
	}
}
