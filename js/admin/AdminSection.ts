export interface MusicAdminSectionConstructor {
  new (): MusicAdminSection;
}

export interface MusicAdminSection {
  mount(element: HTMLElement): void;
}
