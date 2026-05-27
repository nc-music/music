import { MusicAdminSectionConstructor, MusicAdminSection } from './AdminSection'

export default class MusicAdmin {
	#children: Array<MusicAdminSection>;

	constructor(ChildClasses: Array<MusicAdminSectionConstructor>) {
		this.#children = ChildClasses.map(ChildClass => new ChildClass());
	}

	mount(element: HTMLElement) {
		this.#children.forEach(child => child.mount(element));
	}
}
