import { MusicAdminSectionConstructor, MusicAdminSection } from './AdminSection'

export default class MusicAdmin {
	#children: Array<MusicAdminSection>;

	constructor(ChildClasses: Array<MusicAdminSectionConstructor>) {
		this.#children = ChildClasses.map(ChildClass => new ChildClass());
	}

	mount(element: HTMLElement) {
		element.insertAdjacentHTML('afterbegin', '<div class="settings-section"></div>');
		const section: HTMLElement = element.querySelector('.settings-section');
		this.#children.forEach(child => child.mount(section));
	}
}
