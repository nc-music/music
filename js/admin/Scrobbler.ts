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
		const formEl = document.createElement('form');
		formEl.classList.add('scrobbler', this.#identifier);
        const keyLabel = escape(t('music', 'API Key'));
        const secretLabel = escape(t('music', 'API Secret'));
		formEl.insertAdjacentHTML('afterbegin', `
		<fieldset>
			<legend><h3>${escape(this.#name)}</h3></legend>
			<div class="field">
				<label for="${escape(this.#identifier)}_api_key">${keyLabel}</label>
				<input name="api_key" placeholder="${keyLabel}" title="${keyLabel}" id="${escape(this.#identifier)}_api_key" type="text" value="${escape(this.#api_key)}" data-original-value="${escape(this.#api_key)}"/>
				<span class="result"></span>
			</div>
			<div class="field">
				<label for="${escape(this.#identifier)}_api_secret">${secretLabel}</label>
				<input name="api_secret" placeholder="${secretLabel}" title="${secretLabel}" id="${escape(this.#identifier)}_api_secret" type="password" value="${escape(this.#api_secret)}" data-original-value="${escape(this.#api_secret)}"/>
				<span class="result"></span>
			</div>
			<div class="field">
				<button type="submit" title="${escape(t('music', 'Update API credentials for {service}', { service: this.#name }))}">Save</button>
			</div>
		</fieldset>
`);
		element.appendChild(formEl);
		this.#attachListener(formEl);
	}

	#attachListener(formEl: HTMLFormElement) {
		const apiKeyEl = <HTMLInputElement> formEl.elements.namedItem('api_key');
		const apiKeyStatusEl = apiKeyEl.nextElementSibling;
		const apiSecretEl = <HTMLInputElement> formEl.elements.namedItem('api_secret');
		const apiSecretStatusEl = apiSecretEl.nextElementSibling;

		formEl.addEventListener('submit', function (e: SubmitEvent) {
			e.preventDefault();

			setLoadingState(apiKeyStatusEl);
			// Update the api key, then update the api secret if the key update succeeded.
			OCP.AppConfig.setValue('music', apiKeyEl.id, apiKeyEl.value, {
				success: () => {
					setSuccessState(apiKeyStatusEl);

					setLoadingState(apiSecretStatusEl);
					OCP.AppConfig.setValue('music', apiSecretEl.id, apiSecretEl.value, {
						success: () => {
							setSuccessState(apiSecretStatusEl);
						},
						error: (err: any) => {
							setErrorState(apiSecretStatusEl);
						}
					});
				},
				error: (err: any) => {
					setErrorState(apiKeyStatusEl);
				}
			});
		});
	}
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
		console.log(t('music', '<a href={href} target="_blank"', {href: 'foo'}));
		root.insertAdjacentHTML('afterbegin', `
		<h2>${t('music', 'Scrobbler Configuration')}</h2>
		<p>${t('music', 'Configure API connection to begin scrobbling. Check the <a target="_blank" href="{href}">documentation</a> for more details.', {
			href: 'https://github.com/nc-music/music/wiki/'
		})}</p>
		`);
		this.#scrobblers.forEach(scrobbler => scrobbler.mount(root));
		element.appendChild(root);
	}
}

function setLoadingState(el: Element): void {
	el.classList.add('icon-change');
	el.classList.remove('icon-checkmark', 'success', 'icon-error', 'error');
}

function setSuccessState(el: Element): void {
	el.classList.add('icon-checkmark', 'success');
	el.classList.remove('icon-error', 'error', 'icon-change');
}

function setErrorState(el: Element): void {
	el.classList.add('icon-error', 'error');
	el.classList.remove('icon-change', 'icon-checkmark', 'success');
}